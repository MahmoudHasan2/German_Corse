<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/**
 * Fixed-window rate limiter stored in MySQL (no Redis on shared hosting).
 * Buckets are hashed so raw IPs/e-mails are not stored in the table.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** Records one hit and returns whether it is still within the limit. */
    public function hit(string $bucket, int $limit, int $windowSeconds): RateLimitResult
    {
        $key = self::key($bucket);
        $now = $this->clock->now();
        $nowSql = Clock::toSql($now);
        $cutoff = Clock::toSql($now->modify("-{$windowSeconds} seconds"));
        $expires = Clock::toSql($now->modify("+{$windowSeconds} seconds"));

        // Assignment order matters: MySQL evaluates left to right using already-updated values,
        // so window_start is assigned last and the conditions see the old window.
        $this->db->execute(
            'INSERT INTO rate_limits (bucket, window_start, hits, expires_at) VALUES (:b, :now, 1, :exp)
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start <= :cut1, 1, hits + 1),
                expires_at = IF(window_start <= :cut2, :exp2, expires_at),
                window_start = IF(window_start <= :cut3, :now2, window_start)',
            ['b' => $key, 'now' => $nowSql, 'exp' => $expires, 'cut1' => $cutoff, 'cut2' => $cutoff, 'exp2' => $expires, 'cut3' => $cutoff, 'now2' => $nowSql]
        );
        return $this->result($key, $limit, $windowSeconds);
    }

    /** Checks without recording a hit. */
    public function check(string $bucket, int $limit, int $windowSeconds): RateLimitResult
    {
        return $this->result(self::key($bucket), $limit, $windowSeconds);
    }

    public function reset(string $bucket): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE bucket = ?', [self::key($bucket)]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE expires_at < ?', [$this->clock->nowSql()]);
    }

    private function result(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $row = $this->db->one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$key]);
        if ($row === null) {
            return new RateLimitResult(true, $limit, 0);
        }
        $start = Clock::fromSql((string) $row['window_start']);
        $elapsed = $this->clock->now()->getTimestamp() - $start->getTimestamp();
        if ($elapsed >= $windowSeconds) {
            return new RateLimitResult(true, $limit, 0);
        }
        $hits = (int) $row['hits'];
        $retryAfter = max(1, $windowSeconds - $elapsed);
        return new RateLimitResult($hits <= $limit, max(0, $limit - $hits), $hits > $limit ? $retryAfter : 0);
    }

    private static function key(string $bucket): string
    {
        [$prefix] = explode(':', $bucket, 2);
        return substr($prefix, 0, 40) . ':' . hash('sha256', $bucket);
    }
}
