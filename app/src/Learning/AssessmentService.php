<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Auth\UserRepository;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/**
 * Diagnostic and weekly checks. The result is an internal, informal estimate used to
 * suggest a starting point — explicitly NOT a CEFR certificate.
 */
final class AssessmentService
{
    public const PASS_RATIO = 0.67;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly UserRepository $users,
    ) {
    }

    public function create(int $userId, string $kind): int
    {
        return $this->db->insert('assessments', [
            'user_id' => $userId,
            'kind' => $kind,
            'status' => 'in_progress',
            'started_at' => $this->clock->nowSql(),
        ]);
    }

    public function attachSession(int $assessmentId, int $sessionId): void
    {
        $this->db->execute('UPDATE assessments SET session_id = ? WHERE id = ?', [$sessionId, $assessmentId]);
    }

    /**
     * @param list<array{exercise_id:int,level:string,skill:string,outcome:string}> $results
     * @return array<string,mixed>
     */
    public function finalize(int $userId, int $assessmentId, string $kind, array $results): array
    {
        $byLevel = [];
        $bySkill = [];
        foreach ($results as $r) {
            $ok = $r['outcome'] === 'success' ? 1 : 0;
            $byLevel[$r['level']]['total'] = ($byLevel[$r['level']]['total'] ?? 0) + 1;
            $byLevel[$r['level']]['correct'] = ($byLevel[$r['level']]['correct'] ?? 0) + $ok;
            $bySkill[$r['skill']]['total'] = ($bySkill[$r['skill']]['total'] ?? 0) + 1;
            $bySkill[$r['skill']]['correct'] = ($bySkill[$r['skill']]['correct'] ?? 0) + $ok;
        }
        $estimated = null;
        $startLevel = null;
        if ($kind === 'diagnostic') {
            [$estimated, $startLevel] = self::placement($byLevel);
            $this->users->setStartLevel($userId, $startLevel);
        }
        $summary = [
            'by_level' => $byLevel,
            'by_skill' => $bySkill,
            'answered' => count($results),
            'correct' => array_sum(array_map(static fn ($r) => $r['outcome'] === 'success' ? 1 : 0, $results)),
            'estimated_level' => $estimated,
            'recommended_start_level' => $startLevel,
            'disclaimer_ar' => 'هذا تقدير داخلي غير رسمي لاقتراح نقطة البداية، وليس شهادة مستوى معتمدة.',
        ];
        $this->db->execute(
            "UPDATE assessments SET status = 'completed', results = ?, estimated_level = ?, completed_at = ? WHERE id = ? AND user_id = ?",
            [json_encode($summary, JSON_UNESCAPED_UNICODE), $estimated, $this->clock->nowSql(), $assessmentId, $userId]
        );
        return $summary;
    }

    /**
     * Highest level passed with all lower levels passed. Start one level above it (max B1).
     * @param array<string,array{total?:int,correct?:int}> $byLevel
     * @return array{0:?string,1:string}
     */
    public static function placement(array $byLevel): array
    {
        $passed = null;
        foreach (['A1', 'A2', 'B1'] as $level) {
            $t = $byLevel[$level]['total'] ?? 0;
            $c = $byLevel[$level]['correct'] ?? 0;
            if ($t === 0 || $c / $t < self::PASS_RATIO) {
                break;
            }
            $passed = $level;
        }
        $start = match ($passed) {
            null => 'A1',
            'A1' => 'A2',
            default => 'B1',
        };
        return [$passed, $start];
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId, int $limit = 20): array
    {
        $rows = $this->db->all(
            'SELECT id, kind, status, estimated_level, results, started_at, completed_at FROM assessments WHERE user_id = ? ORDER BY started_at DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'status' => (string) $r['status'],
            'estimated_level' => $r['estimated_level'],
            'results' => json_decode((string) ($r['results'] ?? 'null'), true),
            'started_at' => (string) $r['started_at'],
            'completed_at' => $r['completed_at'],
        ], $rows);
    }
}
