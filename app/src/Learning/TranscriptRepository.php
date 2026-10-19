<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/**
 * Text transcripts of recorded answers (the audio itself is deleted right after
 * transcription). A transcript can be used for exactly one answer submission, for
 * the item it was recorded for.
 */
final class TranscriptRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function create(int $userId, int $sessionId, int $exerciseId, string $phase, int $transferIndex, string $text, string $confidence, ?float $probability, float $duration): int
    {
        return $this->db->insert('audio_transcripts', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'exercise_id' => $exerciseId,
            'phase' => $phase,
            'transfer_index' => $transferIndex,
            'text' => $text,
            'confidence' => $confidence,
            'avg_probability' => $probability,
            'duration_seconds' => round($duration, 2),
            'created_at' => $this->clock->nowSql(),
        ]);
    }

    /** @return array<string,mixed>|null unused transcript for exactly this user, session and item */
    public function findUsable(int $id, int $userId, int $sessionId, int $exerciseId, string $phase, int $transferIndex): ?array
    {
        return $this->db->one(
            'SELECT * FROM audio_transcripts WHERE id = ? AND user_id = ? AND session_id = ? AND exercise_id = ? AND phase = ? AND transfer_index = ? AND used_at IS NULL',
            [$id, $userId, $sessionId, $exerciseId, $phase, $transferIndex]
        );
    }

    public function markUsed(int $id): void
    {
        $this->db->execute('UPDATE audio_transcripts SET used_at = ? WHERE id = ?', [$this->clock->nowSql(), $id]);
    }

    /** Retention: unused transcripts are removed after 2 days (lazy cleanup). */
    public function purgeStale(): int
    {
        return $this->db->execute(
            'DELETE FROM audio_transcripts WHERE used_at IS NULL AND created_at < ?',
            [Clock::toSql($this->clock->now()->modify('-2 days'))]
        );
    }

    public static function confidenceLabel(?float $probability, float $lowThreshold): string
    {
        if ($probability === null) {
            return 'unknown';
        }
        if ($probability < $lowThreshold) {
            return 'low';
        }
        return $probability < min(0.95, $lowThreshold + 0.25) ? 'medium' : 'high';
    }
}
