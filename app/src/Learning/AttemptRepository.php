<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Database;

final class AttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findBySubmission(int $sessionId, string $submissionId): ?array
    {
        return $this->db->one('SELECT * FROM answer_attempts WHERE session_id = ? AND client_submission_id = ?', [$sessionId, $submissionId]);
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert('answer_attempts', $row);
    }

    /** @param array<string,mixed> $response */
    public function storeResponse(int $attemptId, array $response): void
    {
        $this->db->execute('UPDATE answer_attempts SET response_json = ? WHERE id = ?', [json_encode($response, JSON_UNESCAPED_UNICODE), $attemptId]);
    }

    /**
     * Previous attempts on the same item in this session (for short AI context and the
     * repeated-error rule).
     * @return list<array{answer:string,verdict:string,outcome:string,categories:list<string>}>
     */
    public function itemHistory(int $sessionId, int $exerciseId, string $phase, int $transferIndex, int $limit = 6): array
    {
        $rows = $this->db->all(
            'SELECT a.id, a.answer_text, a.verdict, a.outcome,
                    (SELECT GROUP_CONCAT(DISTINCT ae.category) FROM attempt_errors ae WHERE ae.attempt_id = a.id) AS cats
             FROM answer_attempts a
             WHERE a.session_id = ? AND a.exercise_id = ? AND a.phase = ? AND COALESCE(a.transfer_index, 0) = ?
             ORDER BY a.id DESC LIMIT ' . max(1, min(20, $limit)),
            [$sessionId, $exerciseId, $phase, $transferIndex]
        );
        return array_reverse(array_map(static fn (array $r) => [
            'answer' => (string) $r['answer_text'],
            'verdict' => (string) $r['verdict'],
            'outcome' => (string) $r['outcome'],
            'categories' => $r['cats'] !== null && $r['cats'] !== '' ? explode(',', (string) $r['cats']) : [],
        ], $rows));
    }

    /** @return list<array<string,mixed>> */
    public function forSession(int $sessionId): array
    {
        return $this->db->all('SELECT * FROM answer_attempts WHERE session_id = ? ORDER BY id', [$sessionId]);
    }
}
