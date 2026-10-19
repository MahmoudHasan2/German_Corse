<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Curriculum\LessonService;
use GermanCoach\Evaluation\AnswerNormalizer;
use GermanCoach\Evaluation\ErrorCategories;
use GermanCoach\Evaluation\Evaluation;
use GermanCoach\Evaluation\ExerciseItem;
use GermanCoach\Mastery\Decision;
use GermanCoach\Mastery\MasteryEngine;
use GermanCoach\Mastery\MasteryPolicy;
use GermanCoach\Mastery\MasteryState;
use GermanCoach\Mastery\OutcomePolicy;
use GermanCoach\Review\ReviewService;

/**
 * The server-controlled tutoring loop (PROJECT_PLAN.md §3.1).
 *
 * States: awaiting_question → awaiting_answer → processing_answer →
 *         needs_retry | awaiting_transfer_task | (next exercise) … → completed
 *
 * The browser only sends answers, hint/skip requests and idempotency keys. Which
 * exercise is current, whether a transfer is needed and whether anything is mastered
 * is decided here, by the deterministic MasteryEngine.
 */
final class SessionService
{
    private readonly MasteryPolicy $policy;
    private readonly MasteryEngine $engine;
    private readonly OutcomePolicy $outcomes;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SessionRepository $sessions,
        private readonly ExerciseRepository $exercises,
        private readonly TurnRepository $turns,
        private readonly AttemptRepository $attempts,
        private readonly TranscriptRepository $transcripts,
        private readonly ExerciseSelector $selector,
        private readonly ProgressRecorder $progress,
        private readonly ReviewService $reviews,
        private readonly LessonService $lessons,
        private readonly AssessmentService $assessments,
        private readonly AnswerEvaluator $evaluator,
        private readonly SessionPresenter $presenter,
    ) {
        $this->policy = MasteryPolicy::fromConfig($config);
        $this->engine = new MasteryEngine($this->policy);
        $this->outcomes = new OutcomePolicy($this->policy);
    }

    // ------------------------------------------------------------------ start

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function startLesson(array $user, int $lessonId): array
    {
        $userId = (int) $user['id'];
        if (!$this->lessons->isAvailable($userId, $user['start_level'] ?? null, $lessonId)) {
            throw new HttpException(403, 'lesson_locked', 'This lesson is not unlocked yet.');
        }
        $existing = $this->sessions->activeForLesson($userId, $lessonId);
        if ($existing !== null) {
            return $this->presenter->view($existing, $userId);
        }
        return $this->db->transaction(function () use ($userId, $lessonId) {
            $id = $this->sessions->create($userId, $lessonId, 'lesson');
            $this->lessons->markStarted($userId, $lessonId);
            $this->advance($id, $userId);
            return $this->presenter->view((array) $this->sessions->findForUser($id, $userId), $userId);
        });
    }

    /** @return array<string,mixed> */
    public function startReview(int $userId): array
    {
        $existing = $this->sessions->activeOfMode($userId, 'review');
        if ($existing !== null) {
            return $this->presenter->view($existing, $userId);
        }
        $due = $this->reviews->due($userId, 'exercise', 10);
        if ($due === []) {
            throw new HttpException(409, 'no_reviews_due', 'Nothing is due for review right now.');
        }
        $items = array_map(static fn ($r) => (int) $r['item_id'], $due);
        return $this->db->transaction(function () use ($userId, $items) {
            $id = $this->sessions->create($userId, null, 'review', ['items' => $items, 'done' => []]);
            $this->advance($id, $userId);
            return $this->presenter->view((array) $this->sessions->findForUser($id, $userId), $userId);
        });
    }

    /** @return array<string,mixed> */
    public function startAssessment(int $userId, string $kind): array
    {
        $existing = $this->sessions->activeOfMode($userId, 'assessment');
        if ($existing !== null) {
            return $this->presenter->view($existing, $userId);
        }
        $items = $kind === 'diagnostic' ? $this->exercises->diagnosticIds() : $this->exercises->weeklyCheckIds($userId);
        if ($items === []) {
            throw new HttpException(409, 'no_assessment_items', 'No assessment items are available. Import the curriculum first.');
        }
        return $this->db->transaction(function () use ($userId, $kind, $items) {
            $assessmentId = $this->assessments->create($userId, $kind);
            $id = $this->sessions->create($userId, null, 'assessment', ['items' => $items, 'done' => [], 'results' => [], 'assessment_id' => $assessmentId, 'kind' => $kind]);
            $this->assessments->attachSession($assessmentId, $id);
            $this->advance($id, $userId);
            return $this->presenter->view((array) $this->sessions->findForUser($id, $userId), $userId);
        });
    }

    /** @return array<string,mixed> */
    public function show(int $userId, int $sessionId): array
    {
        return $this->presenter->view($this->load($userId, $sessionId), $userId);
    }

    // ----------------------------------------------------------------- answer

    /**
     * @param array{answer_text:string,client_submission_id:string,input_mode:string,transcript_id:?int} $in
     * @return array<string,mixed>
     */
    public function answer(int $userId, int $sessionId, array $in): array
    {
        $s = $this->load($userId, $sessionId);

        // Idempotency: the same submission id returns the stored result.
        $existing = $this->attempts->findBySubmission($sessionId, $in['client_submission_id']);
        if ($existing !== null && $existing['response_json'] !== null) {
            $stored = json_decode((string) $existing['response_json'], true);
            return (is_array($stored) ? $stored : []) + ['replayed' => true];
        }
        if ($s['state'] === 'completed') {
            throw HttpException::conflict('session_completed', 'This session is already completed.');
        }
        if ($s['current_exercise_id'] === null) {
            throw HttpException::conflict('no_current_exercise', 'There is no open exercise.');
        }
        $exercise = $this->exercises->find((int) $s['current_exercise_id']);
        if ($exercise === null) {
            throw HttpException::conflict('no_current_exercise', 'There is no open exercise.');
        }
        $phase = (string) $s['phase'];
        $tIdx = (int) $s['transfer_index'];
        $item = ExerciseItem::fromExercise($exercise, $phase, $tIdx);

        $answer = $in['answer_text'];
        $inputMode = $in['input_mode'];
        $transcript = null;
        $transcriptConfidence = 'unknown';
        $edited = false;
        if ($inputMode === 'audio') {
            $transcript = $in['transcript_id'] !== null
                ? $this->transcripts->findUsable($in['transcript_id'], $userId, $sessionId, $item->exerciseId, $phase, $tIdx)
                : null;
            if ($transcript === null) {
                throw HttpException::validation(['transcript_id' => 'invalid_or_used']);
            }
            $transcriptConfidence = (string) $transcript['confidence'];
            $edited = AnswerNormalizer::loose($answer) !== AnswerNormalizer::loose((string) $transcript['text']);
        }

        $prevState = (string) $s['state'];
        $token = bin2hex(random_bytes(16));
        if (!$this->sessions->lockForProcessing($s, $token)) {
            throw HttpException::conflict('answer_in_progress', 'An answer for this session is already being processed.');
        }
        if ($prevState === 'processing_answer') {
            $prevState = $phase === 'transfer' ? 'awaiting_transfer_task' : 'needs_retry';
        }

        try {
            $history = $this->attempts->itemHistory($sessionId, $item->exerciseId, $phase, $tIdx);
            $copied = $s['last_correction'] !== null
                && AnswerNormalizer::similarity($answer, (string) $s['last_correction']) >= $this->policy->copySimilarity;
            $independent = (int) $s['item_attempts'] === 0 && !(bool) $s['hint_used'] && !$copied;

            $evaluation = $this->evaluator->evaluate(
                $userId, $sessionId, $item, $answer, $inputMode, $transcriptConfidence,
                (int) $s['item_attempts'] + 1, (int) $s['strategy_level'], $history
            );
        } catch (ProviderException $e) {
            $this->sessions->unlock($sessionId, $token, $prevState);
            throw new HttpException(503, 'ai_provider_error', $e->userMessageAr(), ['provider_code' => $e->errorCode, 'retryable' => $e->retryable]);
        } catch (\Throwable $e) {
            $this->sessions->unlock($sessionId, $token, $prevState);
            throw $e;
        }

        $derived = $this->outcomes->derive($evaluation, $inputMode, $transcriptConfidence, $edited);
        $countErrors = $derived['count_errors'];
        $state = new MasteryState(
            (string) $s['mode'], $phase, $tIdx, count(ExerciseItem::transferTasks($exercise)),
            (int) $s['item_attempts'], (int) $s['item_failures'], (int) $s['strategy_level'],
            (bool) $exercise['requires_transfer'], (bool) $s['main_assisted'],
            array_values(array_map(static fn ($h) => $h['categories'], array_filter($history, static fn ($h) => $h['outcome'] === 'failure'))),
            (int) $s['exercise_attempts'],
        );
        $decision = $this->engine->decide($state, $derived['outcome'], $derived['reason'], $independent, $countErrors ? $evaluation->errorCategories() : []);

        try {
            return $this->db->transaction(fn () => $this->persist(
                $s, $exercise, $item, $userId, $answer, $in, $transcript, $transcriptConfidence, $edited,
                $evaluation, $derived, $decision, $independent, $copied, $history, $prevState
            ));
        } catch (\Throwable $e) {
            $this->sessions->unlock($sessionId, $token, $prevState);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $exercise
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $transcript
     * @param array{outcome:string,reason:string,count_errors:bool} $derived
     * @param list<array{answer:string,verdict:string,outcome:string,categories:list<string>}> $history
     * @return array<string,mixed>
     */
    private function persist(
        array $s, array $exercise, ExerciseItem $item, int $userId, string $answer, array $in, ?array $transcript,
        string $transcriptConfidence, bool $edited, Evaluation $evaluation, array $derived, Decision $decision,
        bool $independent, bool $copied, array $history, string $prevState,
    ): array {
        $sessionId = (int) $s['id'];
        $outcome = $derived['outcome'];
        $mode = (string) $s['mode'];
        $now = $this->clock->nowSql();

        $attemptId = $this->attempts->insert([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'exercise_id' => $item->exerciseId,
            'phase' => $item->phase,
            'transfer_index' => $item->phase === 'transfer' ? $item->transferIndex : null,
            'attempt_number' => (int) $s['item_attempts'] + 1,
            'input_mode' => $in['input_mode'],
            'answer_text' => $answer,
            'transcript_original' => $transcript['text'] ?? null,
            'transcript_edited' => $edited ? 1 : 0,
            'transcription_confidence' => $in['input_mode'] === 'audio' ? $transcriptConfidence : null,
            'client_submission_id' => $in['client_submission_id'],
            'hint_used' => (int) (bool) $s['hint_used'],
            'copied_correction' => $copied ? 1 : 0,
            'independent' => $independent ? 1 : 0,
            'verdict' => $evaluation->verdict,
            'outcome' => $outcome,
            'evaluator' => $evaluation->evaluator,
            'evaluation' => json_encode($evaluation->toArray(), JSON_UNESCAPED_UNICODE),
            'confidence' => round($evaluation->confidence, 3),
            'created_at' => $now,
        ]);
        if ($transcript !== null) {
            $this->transcripts->markUsed((int) $transcript['id']);
        }

        $repeated = 0;
        if ($derived['count_errors'] && $evaluation->errors !== []) {
            $this->progress->recordErrors($userId, $attemptId, $item->exerciseId, $evaluation);
            foreach ($evaluation->errorCategories() as $cat) {
                $n = 1 + count(array_filter($history, static fn ($h) => in_array($cat, $h['categories'], true)));
                $repeated = max($repeated, $n);
            }
        }
        if ($mode !== 'assessment') {
            $this->progress->applyAttempt($userId, $item->exerciseId, $outcome, $independent, (bool) $s['hint_used'], $item->phase, $decision);
        }

        $reviewInfo = null;
        if ($decision->reviewGrade >= 0 && $mode !== 'assessment') {
            $reviewInfo = $this->reviews->record($userId, 'exercise', $item->exerciseId, $decision->reviewGrade, $repeated, $mode === 'review' ? 'review' : $decision->code);
        }

        $this->db->insert('progression_decisions', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'exercise_id' => $item->exerciseId,
            'attempt_id' => $attemptId,
            'decision' => $decision->code,
            'reason' => mb_substr($decision->reason, 0, 80),
            'from_state' => $prevState,
            'to_state' => $decision->advance ? 'next_exercise' : $decision->nextState,
            'details' => json_encode($decision->toArray() + [
                'outcome' => $outcome, 'verdict' => $evaluation->verdict, 'evaluator' => $evaluation->evaluator,
                'independent' => $independent, 'copied' => $copied, 'hint_used' => (bool) $s['hint_used'],
                'confidence' => $evaluation->confidence, 'input_mode' => $in['input_mode'], 'transcription_confidence' => $transcriptConfidence,
            ]),
            'created_at' => $now,
        ]);

        $active = $this->sessions->touch($s);
        $this->progress->addDailyActivity(
            $userId, $active, 1, $outcome === 'success' ? 1 : 0,
            (int) round((float) ($transcript['duration_seconds'] ?? 0)), $mode === 'review' ? 1 : 0
        );

        // Conversation turns.
        $this->turns->add($sessionId, $userId, $item->exerciseId, 'learner', 'answer', $answer, null, [
            'input_mode' => $in['input_mode'],
            'transcript_original' => $transcript['text'] ?? null,
            'transcript_edited' => $edited,
        ], $attemptId);
        $feedback = $this->feedback($exercise, $item, $evaluation, $outcome, $decision, $mode);
        $this->turns->add(
            $sessionId, $userId, $item->exerciseId, 'tutor', 'feedback',
            $feedback['corrected_answer'] ?? ($outcome === 'success' ? $answer : null),
            $feedback['explanation_ar'],
            ['feedback' => $feedback, 'decision' => ['code' => $decision->code, 'message_ar' => Messages::decision($decision->code)]],
            $attemptId
        );

        // Session state.
        if ($decision->advance) {
            $queue = SessionRepository::queue($s);
            $queue['done'] = array_values(array_unique(array_merge(array_map('intval', $queue['done'] ?? []), [$item->exerciseId])));
            if ($mode === 'assessment') {
                $queue['results'][] = ['exercise_id' => $item->exerciseId, 'level' => (string) ($exercise['diagnostic_level'] ?? $exercise['lesson_level']), 'skill' => $item->skillArea, 'outcome' => $outcome];
            }
            $this->sessions->update($sessionId, ['queue' => $queue, 'processing_token' => null, 'processing_started_at' => null, 'state' => 'awaiting_question']);
            if ($mode === 'lesson' && in_array($decision->exerciseStatus, ['provisionally_mastered', 'needs_review', 'skipped'], true)) {
                $this->lessons->refreshCompletion($userId, (int) $s['lesson_id']);
            }
            $this->advance($sessionId, $userId);
        } else {
            $counted = in_array($outcome, ['success', 'failure', 'unavailable'], true);
            $lastCorrection = $decision->resetItem ? null
                : ($feedback['strategy']['model_answer'] ?? $feedback['corrected_answer'] ?? $s['last_correction']);
            $mainAssisted = (bool) $s['main_assisted'];
            if ($item->phase === 'main' && $decision->nextPhase === 'transfer') {
                $mainAssisted = !$independent;
            }
            $this->sessions->update($sessionId, [
                'state' => $decision->nextState,
                'phase' => $decision->nextPhase,
                'transfer_index' => $decision->nextTransferIndex,
                'item_attempts' => $decision->resetItem ? 0 : (int) $s['item_attempts'] + ($counted ? 1 : 0),
                'item_failures' => $decision->resetItem ? 0 : (int) $s['item_failures'] + ($decision->countsAsFailure ? 1 : 0),
                'exercise_attempts' => (int) $s['exercise_attempts'] + 1,
                'strategy_level' => $decision->strategyLevel,
                'hint_used' => $decision->resetItem ? 0 : (int) (bool) $s['hint_used'],
                'main_assisted' => (int) $mainAssisted,
                'last_correction' => $lastCorrection !== null ? mb_substr((string) $lastCorrection, 0, 1000) : null,
                'processing_token' => null,
                'processing_started_at' => null,
            ]);
            if ($decision->resetItem && $decision->nextPhase === 'transfer') {
                $this->presentItem($sessionId, $userId, $exercise, 'transfer', $decision->nextTransferIndex);
            }
        }

        $fresh = (array) $this->sessions->findForUser($sessionId, $userId);
        $response = [
            'ok' => true,
            'attempt' => [
                'id' => $attemptId,
                'verdict' => $evaluation->verdict,
                'outcome' => $outcome,
                'evaluator' => $evaluation->evaluator,
                'independent' => $independent,
                'hint_used' => (bool) $s['hint_used'],
                'copied_correction' => $copied,
                'input_mode' => $in['input_mode'],
                'answer_text' => $answer,
                'transcript_original' => $transcript['text'] ?? null,
                'transcript_edited' => $edited,
                'transcription_confidence' => $in['input_mode'] === 'audio' ? $transcriptConfidence : null,
            ],
            'feedback' => $feedback,
            'decision' => [
                'code' => $decision->code,
                'reason' => $decision->reason,
                'message_ar' => Messages::decision($decision->code),
                'review' => $reviewInfo,
            ],
            'usage_warning' => $this->evaluator->lastUsageDecision()?->warning ?? false,
            'session' => $this->presenter->view($fresh, $userId),
        ];
        $this->attempts->storeResponse($attemptId, $response);
        return $response;
    }

    // ------------------------------------------------------------- hint/skip

    /** @return array<string,mixed> */
    public function hint(int $userId, int $sessionId): array
    {
        $s = $this->load($userId, $sessionId);
        if ($s['mode'] === 'assessment') {
            throw new HttpException(403, 'hints_disabled', 'Hints are not available in assessments.');
        }
        if (!in_array($s['state'], SessionRepository::ANSWERABLE, true)) {
            throw HttpException::conflict('not_answerable', 'There is no open exercise.');
        }
        $exercise = $this->exercises->find((int) $s['current_exercise_id']);
        if ($exercise === null) {
            throw HttpException::conflict('no_current_exercise', 'There is no open exercise.');
        }
        $item = ExerciseItem::fromExercise($exercise, (string) $s['phase'], (int) $s['transfer_index']);
        if (!(bool) $s['hint_used']) {
            $this->db->transaction(function () use ($s, $userId, $exercise, $item) {
                $this->sessions->update((int) $s['id'], ['hint_used' => 1]);
                $hint = SessionPresenter::hint($exercise, $item);
                $this->turns->add((int) $s['id'], $userId, $item->exerciseId, 'tutor', 'hint', $hint['hint_de'], $hint['hint_ar'], ['context_revealed' => $hint['context_revealed']]);
                $this->db->insert('progression_decisions', [
                    'user_id' => $userId, 'session_id' => (int) $s['id'], 'exercise_id' => $item->exerciseId, 'attempt_id' => null,
                    'decision' => 'hint_requested', 'reason' => 'learner_requested_hint', 'from_state' => (string) $s['state'],
                    'to_state' => (string) $s['state'], 'details' => json_encode(['phase' => $item->phase]), 'created_at' => $this->clock->nowSql(),
                ]);
            });
        }
        return ['ok' => true, 'session' => $this->presenter->view($this->load($userId, $sessionId), $userId)];
    }

    /** @return array<string,mixed> */
    public function skip(int $userId, int $sessionId): array
    {
        $s = $this->load($userId, $sessionId);
        if (!in_array($s['state'], SessionRepository::ANSWERABLE, true) || $s['current_exercise_id'] === null) {
            throw HttpException::conflict('not_answerable', 'There is no open exercise to skip.');
        }
        $token = bin2hex(random_bytes(16));
        if (!$this->sessions->lockForProcessing($s, $token)) {
            throw HttpException::conflict('answer_in_progress', 'This session is busy.');
        }
        try {
            return $this->db->transaction(function () use ($s, $userId, $sessionId) {
                $exerciseId = (int) $s['current_exercise_id'];
                $exercise = (array) $this->exercises->find($exerciseId);
                $mode = (string) $s['mode'];
                $state = new MasteryState($mode, (string) $s['phase'], (int) $s['transfer_index'], count(ExerciseItem::transferTasks($exercise)), (int) $s['item_attempts'], (int) $s['item_failures'], (int) $s['strategy_level'], (bool) $exercise['requires_transfer'], (bool) $s['main_assisted']);
                $decision = $this->engine->skip($state);

                $queue = SessionRepository::queue($s);
                $queue['done'] = array_values(array_unique(array_merge(array_map('intval', $queue['done'] ?? []), [$exerciseId])));
                if ($mode === 'assessment') {
                    $queue['results'][] = ['exercise_id' => $exerciseId, 'level' => (string) ($exercise['diagnostic_level'] ?? $exercise['lesson_level']), 'skill' => (string) $exercise['skill_area'], 'outcome' => 'skipped'];
                } else {
                    if ($mode === 'lesson') {
                        $current = $this->db->scalar('SELECT status FROM user_progress WHERE user_id = ? AND exercise_id = ?', [$userId, $exerciseId]);
                        if ($current !== 'provisionally_mastered') {
                            $this->progress->setStatus($userId, $exerciseId, 'skipped');
                        }
                    }
                    $this->reviews->record($userId, 'exercise', $exerciseId, 1, 0, 'skipped');
                }
                $this->db->insert('progression_decisions', [
                    'user_id' => $userId, 'session_id' => $sessionId, 'exercise_id' => $exerciseId, 'attempt_id' => null,
                    'decision' => $decision->code, 'reason' => $decision->reason, 'from_state' => (string) $s['state'],
                    'to_state' => 'next_exercise', 'details' => json_encode($decision->toArray()), 'created_at' => $this->clock->nowSql(),
                ]);
                $this->progress->addDailyActivity($userId, $this->sessions->touch($s));
                $this->turns->add($sessionId, $userId, $exerciseId, 'system', 'info', null, Messages::decision($decision->code), ['decision' => $decision->code]);
                $this->sessions->update($sessionId, ['queue' => $queue, 'state' => 'awaiting_question', 'processing_token' => null, 'processing_started_at' => null]);
                if ($mode === 'lesson') {
                    $this->lessons->refreshCompletion($userId, (int) $s['lesson_id']);
                }
                $this->advance($sessionId, $userId);
                return [
                    'ok' => true,
                    'decision' => ['code' => $decision->code, 'reason' => $decision->reason, 'message_ar' => Messages::decision($decision->code)],
                    'session' => $this->presenter->view($this->load($userId, $sessionId), $userId),
                ];
            });
        } catch (\Throwable $e) {
            $this->sessions->unlock($sessionId, $token, (string) $s['state']);
            throw $e;
        }
    }

    /** Ends a session early (progress already saved per attempt). @return array<string,mixed> */
    public function complete(int $userId, int $sessionId): array
    {
        $s = $this->load($userId, $sessionId);
        if ($s['state'] === 'processing_answer') {
            throw HttpException::conflict('answer_in_progress', 'Wait until the current answer is processed.');
        }
        if ($s['state'] !== 'completed') {
            $this->db->transaction(function () use ($s, $userId) {
                $this->progress->addDailyActivity($userId, $this->sessions->touch($s));
                $this->finish((array) $this->sessions->findForUser((int) $s['id'], $userId), $userId, true);
            });
        }
        return ['ok' => true, 'session' => $this->presenter->view($this->load($userId, $sessionId), $userId)];
    }

    // --------------------------------------------------------------- internals

    /** @return array<string,mixed> */
    private function load(int $userId, int $sessionId): array
    {
        $s = $this->sessions->findForUser($sessionId, $userId);
        if ($s === null) {
            throw HttpException::notFound('Session not found.');
        }
        return $s;
    }

    /** Selects and presents the next exercise, or completes the session. */
    private function advance(int $sessionId, int $userId): void
    {
        $s = (array) $this->sessions->findForUser($sessionId, $userId);
        $next = $this->selector->next($s, $userId);
        if ($next === null) {
            $this->finish($s, $userId, false);
            return;
        }
        $exercise = $this->exercises->find($next);
        if ($exercise === null) {
            $this->finish($s, $userId, false);
            return;
        }
        $this->sessions->update($sessionId, [
            'current_exercise_id' => $next,
            'state' => 'awaiting_answer',
            'phase' => 'main',
            'transfer_index' => 0,
            'item_attempts' => 0,
            'item_failures' => 0,
            'exercise_attempts' => 0,
            'strategy_level' => 0,
            'hint_used' => 0,
            'main_assisted' => 0,
            'last_correction' => null,
        ]);
        $this->presentItem($sessionId, $userId, $exercise, 'main', 0);
    }

    /** @param array<string,mixed> $exercise */
    private function presentItem(int $sessionId, int $userId, array $exercise, string $phase, int $transferIndex): void
    {
        $item = ExerciseItem::fromExercise($exercise, $phase, $transferIndex);
        if ($phase === 'main' && $item->contextMode === 'listen' && $item->contextDe !== null) {
            $this->turns->add($sessionId, $userId, $item->exerciseId, 'tutor', 'listening_context', $item->contextDe, 'استمع إلى النص (يمكنك تشغيله أكثر من مرة).', null);
        }
        $this->turns->add(
            $sessionId, $userId, $item->exerciseId, 'tutor', $phase === 'transfer' ? 'transfer_question' : 'question',
            $item->promptDe, $item->instructionAr,
            ['phase' => $phase, 'transfer_index' => $transferIndex, 'context_mode' => $item->contextMode, 'context_de' => $item->contextMode === 'read' ? $item->contextDe : null]
        );
    }

    /** @param array<string,mixed> $s */
    private function finish(array $s, int $userId, bool $early): void
    {
        $sessionId = (int) $s['id'];
        $queue = SessionRepository::queue($s);
        $stats = $this->db->one(
            "SELECT COUNT(*) AS attempts, SUM(outcome = 'success') AS successes FROM answer_attempts WHERE session_id = ?",
            [$sessionId]
        ) ?? [];
        $decisions = $this->db->all('SELECT decision, COUNT(*) AS n FROM progression_decisions WHERE session_id = ? GROUP BY decision', [$sessionId]);
        $byDecision = [];
        foreach ($decisions as $d) {
            $byDecision[(string) $d['decision']] = (int) $d['n'];
        }
        $summary = [
            'ended_early' => $early,
            'attempts' => (int) ($stats['attempts'] ?? 0),
            'successes' => (int) ($stats['successes'] ?? 0),
            'mastered' => $byDecision[Decision::MASTERED] ?? 0,
            'needs_review' => ($byDecision[Decision::NEEDS_REVIEW] ?? 0) + ($byDecision[Decision::DEFERRED] ?? 0),
            'skipped' => $byDecision[Decision::SKIPPED] ?? 0,
            'active_minutes' => (int) round(((int) $s['active_seconds']) / 60),
        ];
        if ($s['mode'] === 'lesson' && $s['lesson_id'] !== null) {
            $summary['lesson_completed'] = $this->lessons->refreshCompletion($userId, (int) $s['lesson_id']);
        }
        if ($s['mode'] === 'assessment' && isset($queue['assessment_id'])) {
            $summary['assessment'] = $this->assessments->finalize($userId, (int) $queue['assessment_id'], (string) ($queue['kind'] ?? 'weekly'), $queue['results'] ?? []);
        }
        $queue['summary'] = $summary;
        $this->sessions->update($sessionId, [
            'state' => 'completed',
            'completed_at' => $this->clock->nowSql(),
            'current_exercise_id' => null,
            'queue' => $queue,
            'processing_token' => null,
            'processing_started_at' => null,
        ]);
        $this->turns->add($sessionId, $userId, null, 'system', 'summary', null, $early ? 'تم إنهاء الجلسة. تقدّمك محفوظ.' : 'انتهت الجلسة. أحسنت!', ['summary' => $summary]);
    }

    /**
     * Feedback shown after an attempt. Corrections are only shown when the attempt was
     * actually graded; the explanation strategy escalates with repeated failures.
     * @param array<string,mixed> $exercise
     * @return array<string,mixed>
     */
    private function feedback(array $exercise, ExerciseItem $item, Evaluation $e, string $outcome, Decision $d, string $mode): array
    {
        $graded = in_array($outcome, ['success', 'failure'], true);
        $level = $d->advance ? 0 : $d->strategyLevel;
        $strategy = ['level' => $level, 'label_ar' => Messages::strategy($level)];
        if ($graded && $outcome === 'failure' && $mode !== 'assessment') {
            if ($level >= 1) {
                $strategy['hint_de'] = $exercise['hint_de'];
                $strategy['hint_ar'] = $exercise['hint_ar'];
            }
            if ($level >= 2) {
                $strategy['explanation_ar'] = $exercise['explanation_ar'];
                $notes = json_decode((string) ($exercise['lesson_grammar_notes'] ?? 'null'), true);
                $strategy['grammar_notes'] = is_array($notes) ? array_slice($notes, 0, 2) : [];
            }
            if ($level >= 3) {
                $strategy['model_answer'] = $item->referenceAnswers[0] ?? null;
            }
        }
        $fb = [
            'verdict' => $e->verdict,
            'outcome' => $outcome,
            'evaluator' => $e->evaluator,
            'corrected_answer' => $graded ? $e->correctedAnswer : null,
            'errors' => $graded ? array_map(static fn ($err) => $err + ['category_ar' => ErrorCategories::LABELS_AR[$err['category']] ?? $err['category']], $e->errors) : [],
            'hint_de' => $graded && $outcome === 'failure' ? $e->hintDe : null,
            'explanation_ar' => $e->explanationAr,
            'naturalness' => $graded ? $e->naturalness : null,
            'strategy' => $strategy,
        ];
        if ($outcome === 'unavailable') {
            $fb['reference_answers'] = array_slice($item->referenceAnswers, 0, 3);
        }
        if ($mode === 'assessment') {
            // Assessment: record only; do not teach the answer before the test is done.
            $fb['corrected_answer'] = null;
            $fb['errors'] = [];
            $fb['hint_de'] = null;
            $fb['explanation_ar'] = Messages::decision(Decision::ASSESSMENT_RECORDED);
        }
        return $fb;
    }
}
