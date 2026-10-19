<?php

declare(strict_types=1);

namespace GermanCoach\Review;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/**
 * Persists review_schedule rows and review_log entries. "Due" is computed lazily on
 * request (no cron needed).
 */
final class ReviewService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ReviewScheduler $scheduler,
    ) {
    }

    /** @return array{interval_days:int,due_at:string,reason:string} */
    public function record(int $userId, string $itemType, int $itemId, int $grade, int $repeatedErrors, string $source): array
    {
        $now = $this->clock->now();
        $row = $this->db->one('SELECT * FROM review_schedule WHERE user_id = ? AND item_type = ? AND item_id = ?', [$userId, $itemType, $itemId]);
        $prev = $row === null ? ReviewScheduler::initial() : [
            'interval_days' => (int) $row['interval_days'],
            'ease' => (float) $row['ease'],
            'repetitions' => (int) $row['repetitions'],
            'lapses' => (int) $row['lapses'],
            'last_reviewed_at' => $row['last_reviewed_at'] !== null ? Clock::fromSql((string) $row['last_reviewed_at']) : null,
        ];
        $next = $this->scheduler->schedule($prev, $grade, $now, $repeatedErrors);
        $nowSql = Clock::toSql($now);
        $dueSql = Clock::toSql($next['due_at']);
        $reason = mb_substr($next['reason'], 0, 160);

        if ($row === null) {
            $reviewId = $this->db->insert('review_schedule', [
                'user_id' => $userId, 'item_type' => $itemType, 'item_id' => $itemId, 'due_at' => $dueSql,
                'interval_days' => $next['interval_days'], 'ease' => $next['ease'], 'repetitions' => $next['repetitions'],
                'lapses' => $next['lapses'], 'last_grade' => $grade, 'last_reason' => $reason, 'source' => $source,
                'last_reviewed_at' => $nowSql, 'created_at' => $nowSql, 'updated_at' => $nowSql,
            ]);
        } else {
            $reviewId = (int) $row['id'];
            $this->db->execute(
                'UPDATE review_schedule SET due_at = ?, interval_days = ?, ease = ?, repetitions = ?, lapses = ?, last_grade = ?,
                        last_reason = ?, source = ?, last_reviewed_at = ?, updated_at = ? WHERE id = ?',
                [$dueSql, $next['interval_days'], $next['ease'], $next['repetitions'], $next['lapses'], $grade, $reason, $source, $nowSql, $nowSql, $reviewId]
            );
        }
        $this->db->insert('review_log', [
            'user_id' => $userId, 'review_id' => $reviewId, 'grade' => $grade,
            'interval_before' => $prev['interval_days'], 'interval_after' => $next['interval_days'],
            'reason' => $reason, 'created_at' => $nowSql,
        ]);
        return ['interval_days' => $next['interval_days'], 'due_at' => $dueSql, 'reason' => $reason];
    }

    /** @return list<array<string,mixed>> due items, most overdue first */
    public function due(int $userId, ?string $itemType = null, int $limit = 20): array
    {
        $sql = 'SELECT * FROM review_schedule WHERE user_id = ? AND due_at <= ?';
        $params = [$userId, $this->clock->nowSql()];
        if ($itemType !== null) {
            $sql .= ' AND item_type = ?';
            $params[] = $itemType;
        }
        $sql .= ' ORDER BY due_at ASC, lapses DESC LIMIT ' . max(1, min(100, $limit));
        return $this->db->all($sql, $params);
    }

    public function dueCount(int $userId, ?string $itemType = null): int
    {
        $sql = 'SELECT COUNT(*) FROM review_schedule WHERE user_id = ? AND due_at <= ?';
        $params = [$userId, $this->clock->nowSql()];
        if ($itemType !== null) {
            $sql .= ' AND item_type = ?';
            $params[] = $itemType;
        }
        return (int) $this->db->scalar($sql, $params);
    }

    /** @return list<array<string,mixed>> exercise reviews with display data (upcoming and due) */
    public function exerciseQueue(int $userId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT r.id, r.item_id AS exercise_id, r.due_at, r.interval_days, r.repetitions, r.lapses, r.last_grade, r.last_reason, r.source,
                    e.prompt_de, e.instruction_ar, e.skill_area, e.grammar_focus, l.title_ar AS lesson_title_ar, l.level
             FROM review_schedule r
             JOIN exercises e ON e.id = r.item_id AND r.item_type = \'exercise\'
             JOIN lessons l ON l.id = e.lesson_id
             WHERE r.user_id = ? ORDER BY r.due_at ASC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $reviewId, int $userId): ?array
    {
        return $this->db->one('SELECT * FROM review_schedule WHERE id = ? AND user_id = ?', [$reviewId, $userId]);
    }
}
