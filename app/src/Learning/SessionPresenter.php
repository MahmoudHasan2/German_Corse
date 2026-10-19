<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Evaluation\ExerciseItem;
use GermanCoach\Usage\UsageGuard;

/**
 * Builds the JSON view of a session for the practice screen. It only exposes what the
 * learner may see now: reference answers are never included (except the offline
 * self-check, which is part of the feedback, not the view), and a listening text stays
 * hidden until the learner reveals it (which counts as a hint).
 */
final class SessionPresenter
{
    public function __construct(
        private readonly Database $db,
        private readonly ExerciseRepository $exercises,
        private readonly TurnRepository $turns,
        private readonly UsageGuard $guard,
        private readonly Config $config,
        private readonly \GermanCoach\Ai\ProviderFactory $providers,
    ) {
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    public function view(array $s, int $userId): array
    {
        $sessionId = (int) $s['id'];
        $lesson = $s['lesson_id'] !== null
            ? $this->db->one('SELECT id, title_de, title_ar, level FROM lessons WHERE id = ?', [(int) $s['lesson_id']])
            : null;
        $queue = SessionRepository::queue($s);
        $item = null;
        $hintUsed = (bool) $s['hint_used'];
        $completed = $s['state'] === 'completed';

        if (!$completed && $s['current_exercise_id'] !== null) {
            $exercise = $this->exercises->find((int) $s['current_exercise_id']);
            if ($exercise !== null) {
                $it = ExerciseItem::fromExercise($exercise, (string) $s['phase'], (int) $s['transfer_index']);
                $tasks = ExerciseItem::transferTasks($exercise);
                $showContext = $it->contextMode === 'read' || ($it->contextMode === 'listen' && $hintUsed);
                $item = [
                    'exercise_id' => $it->exerciseId,
                    'type' => $it->type,
                    'skill_area' => $it->skillArea,
                    'grammar_focus' => $it->grammarFocus,
                    'phase' => $it->phase,
                    'transfer_index' => $it->transferIndex,
                    'transfer_count' => count($tasks),
                    'instruction_ar' => $it->instructionAr,
                    'prompt_de' => $it->promptDe,
                    'context_mode' => $it->contextMode,
                    'context_de' => $showContext ? $it->contextDe : null,
                    'min_words' => $it->minWords,
                    'question_turn_id' => $this->turns->latestOfKind($sessionId, $it->phase === 'transfer' ? 'transfer_question' : 'question'),
                    'listening_turn_id' => $it->contextMode === 'listen' ? $this->turns->latestOfKind($sessionId, 'listening_context') : null,
                    'hint_available' => $s['mode'] !== 'assessment' && ($exercise['hint_de'] !== null || $exercise['hint_ar'] !== null || $it->contextMode === 'listen'),
                    'hint' => $hintUsed ? self::hint($exercise, $it) : null,
                ];
            }
        }

        $usage = $this->guard->check($userId, $sessionId, 'text', 0.0, 0.0, false);
        $total = $s['mode'] === 'lesson' && $lesson !== null
            ? $this->exercises->lessonExerciseCount((int) $lesson['id'])
            : count($queue['items'] ?? []);
        $mastered = $s['mode'] === 'lesson' && $lesson !== null ? $this->exercises->lessonMasteredCount((int) $lesson['id'], $userId) : null;

        return [
            'id' => $sessionId,
            'mode' => (string) $s['mode'],
            'state' => (string) $s['state'],
            'completed' => $completed,
            'lesson' => $lesson !== null ? [
                'id' => (int) $lesson['id'], 'title_de' => (string) $lesson['title_de'], 'title_ar' => (string) $lesson['title_ar'], 'level' => (string) $lesson['level'],
            ] : null,
            'progress' => [
                'handled_in_session' => count($queue['done'] ?? []),
                'total' => $total,
                'mastered' => $mastered,
            ],
            'item' => $item,
            'attempts_on_item' => (int) $s['item_attempts'],
            'failures_on_item' => (int) $s['item_failures'],
            'max_attempts' => max(2, $this->config->int('MASTERY_MAX_ATTEMPTS', 5)),
            'strategy_level' => (int) $s['strategy_level'],
            'hint_used' => $hintUsed,
            'turns' => array_map(fn (array $t) => $this->turn($t, $s), $this->turns->recent($sessionId, $userId)),
            'ai' => [
                'available' => $usage->allowed,
                'reason' => $usage->reason,
                'message_ar' => $usage->allowed ? null : $usage->messageAr(),
                'warning' => $usage->warning,
                'demo_provider' => $this->providers->provider('tutor') === 'fake',
                'tts_mode' => $this->providers->provider('tts') === 'browser' ? 'browser' : 'server',
            ],
            'audio' => [
                'max_seconds' => $this->config->int('AUDIO_MAX_SECONDS', 60),
                'max_bytes' => $this->config->int('AUDIO_MAX_UPLOAD_BYTES', 5_242_880),
            ],
            'summary' => $completed ? ($queue['summary'] ?? null) : null,
        ];
    }

    /** @param array<string,mixed> $exercise @return array<string,mixed> */
    public static function hint(array $exercise, ExerciseItem $it): array
    {
        return [
            'hint_de' => $exercise['hint_de'] ?? null,
            'hint_ar' => $exercise['hint_ar'] ?? null,
            'context_revealed' => $it->contextMode === 'listen',
        ];
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $s @return array<string,mixed> */
    private function turn(array $t, array $s): array
    {
        $kind = (string) $t['kind'];
        $hidden = $kind === 'listening_context'
            && (int) $t['exercise_id'] === (int) $s['current_exercise_id']
            && !(bool) $s['hint_used']
            && $s['state'] !== 'completed';
        return [
            'id' => (int) $t['id'],
            'role' => (string) $t['role'],
            'kind' => $kind,
            'text_de' => $hidden ? null : $t['text_de'],
            'text_ar' => $t['text_ar'],
            'hidden' => $hidden,
            'has_audio' => $t['role'] === 'tutor' && $t['text_de'] !== null && $t['text_de'] !== '',
            'payload' => json_decode((string) ($t['payload'] ?? 'null'), true),
            'created_at' => (string) $t['created_at'],
        ];
    }
}
