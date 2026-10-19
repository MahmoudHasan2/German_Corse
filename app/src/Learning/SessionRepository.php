<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/** learning_sessions access. Every read is scoped by user_id. */
final class SessionRepository
{
    public const ANSWERABLE = ['awaiting_answer', 'needs_retry', 'awaiting_transfer_task'];
    private const STALE_LOCK_SECONDS = 150;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $sessionId, int $userId): ?array
    {
        return $this->db->one('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
    }

    /** @return array<string,mixed>|null */
    public function activeForLesson(int $userId, int $lessonId): ?array
    {
        return $this->db->one(
            "SELECT * FROM learning_sessions WHERE user_id = ? AND lesson_id = ? AND mode = 'lesson' AND state <> 'completed' ORDER BY id DESC LIMIT 1",
            [$userId, $lessonId]
        );
    }

    /** @return array<string,mixed>|null */
    public function activeOfMode(int $userId, string $mode): ?array
    {
        return $this->db->one(
            "SELECT * FROM learning_sessions WHERE user_id = ? AND mode = ? AND state <> 'completed' ORDER BY id DESC LIMIT 1",
            [$userId, $mode]
        );
    }

    /** @param array<string,mixed>|null $queue */
    public function create(int $userId, ?int $lessonId, string $mode, ?array $queue = null): int
    {
        $now = $this->clock->nowSql();
        return $this->db->insert('learning_sessions', [
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'mode' => $mode,
            'state' => 'awaiting_question',
            'queue' => $queue !== null ? json_encode($queue) : null,
            'started_at' => $now,
            'last_activity_at' => $now,
        ]);
    }

    /**
     * Atomically moves an answerable session into processing_answer (compare-and-set on version).
     * A lock older than STALE_LOCK_SECONDS (crashed request) may be taken over.
     */
    public function lockForProcessing(array $session, string $token): bool
    {
        $now = $this->clock->now();
        $stale = $session['state'] === 'processing_answer'
            && $session['processing_started_at'] !== null
            && ($now->getTimestamp() - Clock::fromSql((string) $session['processing_started_at'])->getTimestamp()) > self::STALE_LOCK_SECONDS;
        if (!$stale && !in_array($session['state'], self::ANSWERABLE, true)) {
            return false;
        }
        return $this->db->execute(
            "UPDATE learning_sessions
             SET state = 'processing_answer', processing_token = ?, processing_started_at = ?, version = version + 1
             WHERE id = ? AND user_id = ? AND version = ?",
            [$token, Clock::toSql($now), (int) $session['id'], (int) $session['user_id'], (int) $session['version']]
        ) === 1;
    }

    public function unlock(int $sessionId, string $token, string $state): void
    {
        $this->db->execute(
            "UPDATE learning_sessions SET state = ?, processing_token = NULL, processing_started_at = NULL, version = version + 1
             WHERE id = ? AND processing_token = ?",
            [$state, $sessionId, $token]
        );
    }

    /** @param array<string,mixed> $fields trusted column names from code only */
    public function update(int $sessionId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($fields as $col => $value) {
            if (!preg_match('/^[a-z_]+$/', $col)) {
                throw new \InvalidArgumentException('bad column');
            }
            $sets[] = "`$col` = ?";
            $params[] = is_array($value) ? json_encode($value) : $value;
        }
        $params[] = $sessionId;
        $this->db->execute('UPDATE learning_sessions SET ' . implode(', ', $sets) . ', version = version + 1 WHERE id = ?', $params);
    }

    /**
     * Adds bounded active time since the last interaction (max 3 minutes per step,
     * so idle tabs don't inflate study time). Returns the seconds added.
     */
    public function touch(array $session): int
    {
        $now = $this->clock->now();
        $last = Clock::fromSql((string) $session['last_activity_at']);
        $delta = max(0, min(180, $now->getTimestamp() - $last->getTimestamp()));
        $this->db->execute(
            'UPDATE learning_sessions SET active_seconds = active_seconds + ?, last_activity_at = ? WHERE id = ?',
            [$delta, Clock::toSql($now), (int) $session['id']]
        );
        return $delta;
    }

    /** @return array<string,mixed> */
    public static function queue(array $session): array
    {
        $q = json_decode((string) ($session['queue'] ?? 'null'), true);
        return is_array($q) ? $q : [];
    }
}
