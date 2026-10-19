<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;
use GermanCoach\Evaluation\Evaluation;
use GermanCoach\Mastery\Decision;

/** Writes per-exercise progress, error memory and daily activity. Called inside the attempt transaction. */
final class ProgressRecorder
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function applyAttempt(int $userId, int $exerciseId, string $outcome, bool $independent, bool $hintUsed, string $phase, Decision $d): void
    {
        $now = $this->clock->nowSql();
        $success = $outcome === 'success' ? 1 : 0;
        $status = $d->exerciseStatus ?? 'in_progress';
        $this->db->execute(
            "INSERT INTO user_progress (user_id, exercise_id, status, attempts_total, successes, independent_successes, hint_attempts,
                                        transfer_successes, failures, last_attempt_at, mastered_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = ?,
                attempts_total = attempts_total + 1,
                successes = successes + ?,
                independent_successes = independent_successes + ?,
                hint_attempts = hint_attempts + ?,
                transfer_successes = transfer_successes + ?,
                failures = failures + ?,
                last_attempt_at = ?,
                mastered_at = IF(? = 'provisionally_mastered', ?, mastered_at),
                updated_at = ?",
            [
                $userId, $exerciseId, $status, $success, $success && $independent ? 1 : 0, $hintUsed ? 1 : 0,
                $success && $phase === 'transfer' ? 1 : 0, $d->countsAsFailure ? 1 : 0, $now,
                $status === 'provisionally_mastered' ? $now : null, $now,
                $status, $success, $success && $independent ? 1 : 0, $hintUsed ? 1 : 0,
                $success && $phase === 'transfer' ? 1 : 0, $d->countsAsFailure ? 1 : 0, $now, $status, $now, $now,
            ]
        );
    }

    public function setStatus(int $userId, int $exerciseId, string $status): void
    {
        $now = $this->clock->nowSql();
        $this->db->execute(
            'INSERT INTO user_progress (user_id, exercise_id, status, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = ?, updated_at = ?',
            [$userId, $exerciseId, $status, $now, $status, $now]
        );
    }

    /** Stores the attempt's errors and updates the per-category error memory. Returns the max repeat count. */
    public function recordErrors(int $userId, int $attemptId, int $exerciseId, Evaluation $e): int
    {
        $now = $this->clock->nowSql();
        $maxOccurrences = 0;
        foreach ($e->errors as $err) {
            $this->db->insert('attempt_errors', [
                'attempt_id' => $attemptId,
                'user_id' => $userId,
                'category' => $err['category'],
                'original_text' => mb_substr($err['original'], 0, 300),
                'correction' => mb_substr($err['correction'], 0, 300),
                'explanation_ar' => mb_substr($err['explanation_ar'], 0, 600),
                'created_at' => $now,
            ]);
        }
        foreach ($e->errorCategories() as $category) {
            $first = null;
            foreach ($e->errors as $err) {
                if ($err['category'] === $category) {
                    $first = $err;
                    break;
                }
            }
            $this->db->execute(
                'INSERT INTO error_patterns (user_id, category, occurrences, last_original, last_correction, last_explanation_ar, last_exercise_id, first_seen_at, last_seen_at)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_original = ?, last_correction = ?,
                    last_explanation_ar = ?, last_exercise_id = ?, last_seen_at = ?',
                [
                    $userId, $category, mb_substr((string) $first['original'], 0, 300), mb_substr((string) $first['correction'], 0, 300),
                    mb_substr((string) $first['explanation_ar'], 0, 600), $exerciseId, $now, $now,
                    mb_substr((string) $first['original'], 0, 300), mb_substr((string) $first['correction'], 0, 300),
                    mb_substr((string) $first['explanation_ar'], 0, 600), $exerciseId, $now,
                ]
            );
            $maxOccurrences = max($maxOccurrences, (int) $this->db->scalar(
                'SELECT occurrences FROM error_patterns WHERE user_id = ? AND category = ?',
                [$userId, $category]
            ));
        }
        return $maxOccurrences;
    }

    public function addDailyActivity(int $userId, int $activeSeconds, int $attempts = 0, int $successes = 0, int $audioSeconds = 0, int $reviews = 0): void
    {
        $date = $this->clock->now()->format('Y-m-d');
        $this->db->execute(
            'INSERT INTO daily_activity (user_id, activity_date, active_seconds, attempts, successes, audio_seconds, reviews)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE active_seconds = active_seconds + ?, attempts = attempts + ?, successes = successes + ?,
                audio_seconds = audio_seconds + ?, reviews = reviews + ?',
            [$userId, $date, $activeSeconds, $attempts, $successes, $audioSeconds, $reviews, $activeSeconds, $attempts, $successes, $audioSeconds, $reviews]
        );
    }
}
