<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

/**
 * Server-side choice of the next exercise. The browser never chooses.
 *
 * Lesson mode: unfinished exercises in curriculum order first; then — weakest first
 * (most failures) — exercises that were skipped or deferred, excluding those already
 * handled in this session so the learner is not looped back immediately.
 * Review / assessment mode: a fixed queue planned when the session started.
 */
final class ExerciseSelector
{
    public function __construct(private readonly ExerciseRepository $exercises)
    {
    }

    /** @param array<string,mixed> $session */
    public function next(array $session, int $userId): ?int
    {
        $queue = SessionRepository::queue($session);
        $done = array_map('intval', $queue['done'] ?? []);

        if ($session['mode'] !== 'lesson') {
            foreach (array_map('intval', $queue['items'] ?? []) as $id) {
                if (!in_array($id, $done, true)) {
                    return $id;
                }
            }
            return null;
        }

        $rows = $this->exercises->forLessonWithProgress((int) $session['lesson_id'], $userId);
        foreach ($rows as $r) {
            if (in_array($r['status'], ['not_started', 'in_progress'], true) && !in_array((int) $r['id'], $done, true)) {
                return (int) $r['id'];
            }
        }
        $weak = array_filter($rows, static fn ($r) => in_array($r['status'], ['skipped', 'needs_review'], true) && !in_array((int) $r['id'], $done, true));
        usort($weak, static fn ($a, $b) => [(int) $b['failures'], (int) $a['sort_order']] <=> [(int) $a['failures'], (int) $b['sort_order']]);
        $first = array_values($weak)[0] ?? null;
        return $first !== null ? (int) $first['id'] : null;
    }
}
