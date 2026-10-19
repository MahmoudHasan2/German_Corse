<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Database;

final class ExerciseRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null exercise row with lesson_level and lesson meta */
    public function find(int $exerciseId): ?array
    {
        return $this->db->one(
            'SELECT e.*, l.level AS lesson_level, l.title_de AS lesson_title_de, l.title_ar AS lesson_title_ar, l.grammar_notes AS lesson_grammar_notes
             FROM exercises e JOIN lessons l ON l.id = e.lesson_id WHERE e.id = ?',
            [$exerciseId]
        );
    }

    /** @return list<array<string,mixed>> active exercises of a lesson with the user's progress */
    public function forLessonWithProgress(int $lessonId, int $userId): array
    {
        return $this->db->all(
            "SELECT e.id, e.sort_order, COALESCE(up.status, 'not_started') AS status, COALESCE(up.failures, 0) AS failures
             FROM exercises e LEFT JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.lesson_id = ? AND e.is_active = 1 ORDER BY e.sort_order, e.id",
            [$userId, $lessonId]
        );
    }

    /** @return list<int> */
    public function diagnosticIds(): array
    {
        return array_map('intval', array_column($this->db->all(
            "SELECT e.id FROM exercises e JOIN lessons l ON l.id = e.lesson_id
             WHERE e.diagnostic_level IS NOT NULL AND e.is_active = 1 AND l.is_active = 1
             ORDER BY FIELD(e.diagnostic_level, 'A1', 'A2', 'B1'), e.sort_order, e.id"
        ), 'id'));
    }

    /**
     * Weekly check items: exercises from lessons the user completed or worked on,
     * preferring the least recently practised. Falls back to the diagnostic set.
     * @return list<int>
     */
    public function weeklyCheckIds(int $userId, int $limit = 8): array
    {
        $ids = array_map('intval', array_column($this->db->all(
            "SELECT e.id FROM exercises e
             JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.is_active = 1 AND e.diagnostic_level IS NULL AND up.status IN ('provisionally_mastered','needs_review','skipped')
             ORDER BY up.last_attempt_at ASC, e.id LIMIT " . max(1, min(20, $limit)),
            [$userId]
        ), 'id'));
        return count($ids) >= 3 ? $ids : $this->diagnosticIds();
    }

    public function lessonExerciseCount(int $lessonId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM exercises WHERE lesson_id = ? AND is_active = 1', [$lessonId]);
    }

    public function lessonMasteredCount(int $lessonId, int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM exercises e JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.lesson_id = ? AND e.is_active = 1 AND up.status = 'provisionally_mastered'",
            [$userId, $lessonId]
        );
    }
}
