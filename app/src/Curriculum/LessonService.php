<?php

declare(strict_types=1);

namespace GermanCoach\Curriculum;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;

/**
 * Lesson availability and completion. A lesson is available when every prerequisite
 * is completed, or the prerequisite lies below the learner's placement level.
 * A lesson is completed when every active exercise was worked on (mastered, skipped
 * or deferred) and the mastered share reaches LESSON_MIN_MASTERED_RATIO.
 */
final class LessonService
{
    private const LEVEL_RANK = ['A1' => 1, 'A2' => 2, 'B1' => 3];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function lessonsForUser(int $userId, ?string $startLevel): array
    {
        $rows = $this->db->all(
            "SELECT l.id, l.slug, l.level, l.category, l.title_de, l.title_ar, l.objective_ar, l.estimated_minutes, l.skills,
                    COUNT(e.id) AS total,
                    SUM(CASE WHEN up.status = 'provisionally_mastered' THEN 1 ELSE 0 END) AS mastered,
                    SUM(CASE WHEN up.status IN ('provisionally_mastered','skipped','needs_review') THEN 1 ELSE 0 END) AS touched,
                    SUM(CASE WHEN up.status IN ('skipped','needs_review') THEN 1 ELSE 0 END) AS pending_review,
                    ul.status AS user_status, ul.completed_at
             FROM lessons l
             JOIN exercises e ON e.lesson_id = l.id AND e.is_active = 1
             LEFT JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             LEFT JOIN user_lessons ul ON ul.lesson_id = l.id AND ul.user_id = ?
             WHERE l.is_active = 1 AND l.kind = 'lesson'
             GROUP BY l.id, l.slug, l.level, l.category, l.title_de, l.title_ar, l.objective_ar, l.estimated_minutes, l.skills, ul.status, ul.completed_at
             ORDER BY FIELD(l.level, 'A1', 'A2', 'B1'), l.sort_order, l.id",
            [$userId, $userId]
        );
        $prereqs = $this->prerequisites();
        $completed = [];
        $levels = [];
        foreach ($rows as $r) {
            $completed[(int) $r['id']] = $r['user_status'] === 'completed';
            $levels[(int) $r['id']] = (string) $r['level'];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $available = $this->prerequisitesMet($prereqs[$id] ?? [], $completed, $levels, $startLevel);
            $status = $completed[$id] ? 'completed' : ($r['user_status'] === 'in_progress' ? 'in_progress' : ($available ? 'available' : 'locked'));
            $out[] = [
                'id' => $id,
                'slug' => (string) $r['slug'],
                'level' => (string) $r['level'],
                'category' => (string) $r['category'],
                'title_de' => (string) $r['title_de'],
                'title_ar' => (string) $r['title_ar'],
                'objective_ar' => (string) $r['objective_ar'],
                'estimated_minutes' => (int) $r['estimated_minutes'],
                'skills' => json_decode((string) $r['skills'], true) ?: [],
                'status' => $status,
                'available' => $available || $completed[$id],
                'exercises_total' => (int) $r['total'],
                'exercises_mastered' => (int) $r['mastered'],
                'exercises_pending_review' => (int) $r['pending_review'],
                'requires' => array_map(static fn (int $p) => $p, $prereqs[$id] ?? []),
            ];
        }
        return $out;
    }

    public function isAvailable(int $userId, ?string $startLevel, int $lessonId): bool
    {
        foreach ($this->lessonsForUser($userId, $startLevel) as $l) {
            if ($l['id'] === $lessonId) {
                return $l['available'];
            }
        }
        return false;
    }

    /**
     * Lesson content for the learner. Reference answers are deliberately NOT included:
     * the learner sees model answers only through the tutoring loop.
     * @return array<string,mixed>
     */
    public function detail(int $userId, ?string $startLevel, int $lessonId): array
    {
        $lesson = $this->db->one("SELECT * FROM lessons WHERE id = ? AND is_active = 1 AND kind = 'lesson'", [$lessonId]);
        if ($lesson === null) {
            throw HttpException::notFound('Lesson not found.');
        }
        $summary = null;
        foreach ($this->lessonsForUser($userId, $startLevel) as $l) {
            if ($l['id'] === $lessonId) {
                $summary = $l;
            }
        }
        $exercises = $this->db->all(
            "SELECT e.id, e.type, e.skill_area, e.grammar_focus, e.instruction_ar, COALESCE(up.status, 'not_started') AS status
             FROM exercises e LEFT JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.lesson_id = ? AND e.is_active = 1 ORDER BY e.sort_order, e.id",
            [$userId, $lessonId]
        );
        $vocab = $this->db->all(
            'SELECT id, word_de, article, plural, word_ar, example_de, part_of_speech FROM vocabulary WHERE lesson_id = ? AND is_active = 1 ORDER BY id',
            [$lessonId]
        );
        return [
            'lesson' => $summary,
            'description_ar' => $lesson['description_ar'],
            'grammar_notes' => json_decode((string) ($lesson['grammar_notes'] ?? 'null'), true) ?: [],
            'dialogue' => json_decode((string) ($lesson['dialogue'] ?? 'null'), true) ?: [],
            'review_targets' => json_decode((string) ($lesson['review_targets'] ?? 'null'), true) ?: [],
            'exercises' => array_map(static fn (array $e) => [
                'id' => (int) $e['id'],
                'type' => (string) $e['type'],
                'skill_area' => (string) $e['skill_area'],
                'grammar_focus' => $e['grammar_focus'],
                'instruction_ar' => (string) $e['instruction_ar'],
                'status' => (string) $e['status'],
            ], $exercises),
            'vocabulary' => array_map(static fn (array $v) => [
                'id' => (int) $v['id'],
                'word_de' => (string) $v['word_de'],
                'article' => $v['article'],
                'plural' => $v['plural'],
                'word_ar' => (string) $v['word_ar'],
                'example_de' => $v['example_de'],
                'part_of_speech' => (string) $v['part_of_speech'],
            ], $vocab),
        ];
    }

    public function markStarted(int $userId, int $lessonId): void
    {
        $this->db->execute(
            "INSERT INTO user_lessons (user_id, lesson_id, status, started_at) VALUES (?, ?, 'in_progress', ?)
             ON DUPLICATE KEY UPDATE user_id = user_id",
            [$userId, $lessonId, $this->clock->nowSql()]
        );
    }

    /** Recomputes completion from user_progress; returns true when the lesson is (now) completed. */
    public function refreshCompletion(int $userId, int $lessonId): bool
    {
        $row = $this->db->one(
            "SELECT COUNT(e.id) AS total,
                    SUM(CASE WHEN up.status = 'provisionally_mastered' THEN 1 ELSE 0 END) AS mastered,
                    SUM(CASE WHEN up.status IN ('provisionally_mastered','skipped','needs_review') THEN 1 ELSE 0 END) AS touched
             FROM exercises e LEFT JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.lesson_id = ? AND e.is_active = 1",
            [$userId, $lessonId]
        );
        $total = (int) ($row['total'] ?? 0);
        if ($total === 0) {
            return false;
        }
        $ratio = (int) $row['mastered'] / $total;
        $done = (int) $row['touched'] === $total && $ratio >= $this->config->float('LESSON_MIN_MASTERED_RATIO', 0.7);
        if ($done) {
            $this->db->execute(
                "INSERT INTO user_lessons (user_id, lesson_id, status, started_at, completed_at) VALUES (?, ?, 'completed', ?, ?)
                 ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, ?)",
                [$userId, $lessonId, $this->clock->nowSql(), $this->clock->nowSql(), $this->clock->nowSql()]
            );
        }
        return $done;
    }

    /** @return array<int,list<int>> lesson id => required lesson ids */
    private function prerequisites(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT lesson_id, requires_lesson_id FROM lesson_prerequisites') as $r) {
            $out[(int) $r['lesson_id']][] = (int) $r['requires_lesson_id'];
        }
        return $out;
    }

    /**
     * @param list<int> $required
     * @param array<int,bool> $completed
     * @param array<int,string> $levels
     */
    private function prerequisitesMet(array $required, array $completed, array $levels, ?string $startLevel): bool
    {
        $startRank = self::LEVEL_RANK[$startLevel ?? 'A1'] ?? 1;
        foreach ($required as $req) {
            if ($completed[$req] ?? false) {
                continue;
            }
            $reqRank = self::LEVEL_RANK[$levels[$req] ?? 'A1'] ?? 1;
            if ($reqRank < $startRank) {
                continue; // placed above this level by the diagnostic
            }
            return false;
        }
        return true;
    }
}
