<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Clock;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;
use GermanCoach\Learning\AssessmentService;
use GermanCoach\Learning\ProgressRecorder;
use GermanCoach\Learning\SessionService;
use GermanCoach\Review\ReviewService;

final class ReviewController
{
    /** Self-graded flashcards (no AI): button → scheduler grade. */
    public const CARD_GRADES = ['again' => 1, 'hard' => 3, 'good' => 4, 'easy' => 5];

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly SessionService $sessions,
        private readonly AssessmentService $assessments,
        private readonly ProgressRecorder $progress,
        private readonly Clock $clock,
    ) {
    }

    /** GET /api/reviews — due counts and the exercise review queue (due and upcoming). */
    public function index(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $now = $this->clock->now();
        $queue = array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'exercise_id' => (int) $r['exercise_id'],
            'prompt_de' => (string) $r['prompt_de'],
            'instruction_ar' => (string) $r['instruction_ar'],
            'skill_area' => (string) $r['skill_area'],
            'lesson_title_ar' => (string) $r['lesson_title_ar'],
            'level' => (string) $r['level'],
            'due_at' => (string) $r['due_at'],
            'is_due' => Clock::fromSql((string) $r['due_at']) <= $now,
            'interval_days' => (int) $r['interval_days'],
            'lapses' => (int) $r['lapses'],
            'last_reason' => $r['last_reason'],
            'source' => (string) $r['source'],
        ], $this->reviews->exerciseQueue($userId));
        return Response::json([
            'ok' => true,
            'due' => ['exercises' => $this->reviews->dueCount($userId, 'exercise'), 'vocabulary' => $this->reviews->dueCount($userId, 'vocabulary')],
            'queue' => $queue,
        ]);
    }

    /** POST /api/reviews/start — opens a review session with up to 10 due exercises. */
    public function start(Request $request): Response
    {
        return Response::json(['ok' => true, 'session' => $this->sessions->startReview((int) $request->attribute('user')['id'])], 201);
    }

    /**
     * POST /api/reviews/{id}/complete {grade: again|hard|good|easy}
     * Completes a vocabulary card review. Exercise reviews are completed through a
     * review session, where the answer is actually evaluated.
     */
    public function complete(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $review = $this->reviews->findForUser($request->routeInt('id'), $userId) ?? throw HttpException::notFound('Review not found.');
        if ($review['item_type'] !== 'vocabulary') {
            throw HttpException::conflict('use_review_session', 'Exercise reviews are completed in a review session.');
        }
        $v = new Validator($request->input());
        $grade = $v->enum('grade', array_keys(self::CARD_GRADES));
        $elapsedMs = $v->int('elapsed_ms', 0, PHP_INT_MAX, false) ?? 0;
        $v->validate();
        $result = $this->reviews->record($userId, 'vocabulary', (int) $review['item_id'], self::CARD_GRADES[(string) $grade], 0, 'vocab');
        $this->progress->addDailyActivity($userId, intdiv(min($elapsedMs, 120_000), 1000), 0, 0, 0, 1);
        return Response::json(['ok' => true, 'next' => $result]);
    }

    /** POST /api/assessments/start {kind: diagnostic|weekly} */
    public function startAssessment(Request $request): Response
    {
        $v = new Validator($request->input());
        $kind = $v->enum('kind', ['diagnostic', 'weekly']);
        $v->validate();
        return Response::json(['ok' => true, 'session' => $this->sessions->startAssessment((int) $request->attribute('user')['id'], (string) $kind)], 201);
    }

    /** GET /api/assessments */
    public function assessments(Request $request): Response
    {
        return Response::json(['ok' => true, 'assessments' => $this->assessments->history((int) $request->attribute('user')['id'])]);
    }
}
