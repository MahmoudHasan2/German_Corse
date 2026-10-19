<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/** Conversation turns shown in the practice view. Text is plain text, rendered escaped. */
final class TurnRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed>|null $payload */
    public function add(int $sessionId, int $userId, ?int $exerciseId, string $role, string $kind, ?string $textDe, ?string $textAr, ?array $payload = null, ?int $attemptId = null): int
    {
        return $this->db->insert('conversation_turns', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'exercise_id' => $exerciseId,
            'attempt_id' => $attemptId,
            'role' => $role,
            'kind' => $kind,
            'text_de' => $textDe,
            'text_ar' => $textAr,
            'payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $this->clock->nowSql(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $sessionId, int $userId, int $limit = 40): array
    {
        $rows = $this->db->all(
            'SELECT * FROM conversation_turns WHERE session_id = ? AND user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$sessionId, $userId]
        );
        return array_reverse($rows);
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $turnId, int $userId): ?array
    {
        return $this->db->one('SELECT * FROM conversation_turns WHERE id = ? AND user_id = ?', [$turnId, $userId]);
    }

    /** The latest tutor turn of a kind for the current item (e.g. the question to replay). */
    public function latestOfKind(int $sessionId, string $kind): ?int
    {
        $id = $this->db->scalar('SELECT id FROM conversation_turns WHERE session_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$sessionId, $kind]);
        return $id === null ? null : (int) $id;
    }
}
