<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

use DateTimeImmutable;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/** Server-side accounting of every provider call (successful or failed). */
final class UsageRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function record(
        ?int $userId,
        ?int $sessionId,
        string $provider,
        string $service,
        string $model,
        string $status,
        ProviderUsage $usage,
        float $cost,
        ?int $latencyMs,
        ?string $errorCode = null,
        ?string $providerRequestId = null,
    ): void {
        $this->db->insert('api_usage', [
            'request_uid' => bin2hex(random_bytes(16)),
            'user_id' => $userId,
            'session_id' => $sessionId,
            'provider' => mb_substr($provider, 0, 30),
            'service' => $service,
            'model' => mb_substr($model, 0, 80),
            'status' => $status,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cached_tokens' => $usage->cachedTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'audio_seconds' => $usage->audioSeconds !== null ? round($usage->audioSeconds, 2) : null,
            'characters' => $usage->characters,
            'estimated_cost_usd' => round(max(0.0, $cost), 6),
            'cost_basis' => $usage->reportedByProvider ? 'actual_usage' : 'estimate',
            'provider_request_id' => $providerRequestId !== null ? mb_substr($providerRequestId, 0, 100) : null,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
            'created_at' => $this->clock->nowSql(),
        ]);
        if ($sessionId !== null && $status === 'ok') {
            $this->db->execute(
                'UPDATE learning_sessions SET ai_requests = ai_requests + 1, audio_seconds = audio_seconds + ? WHERE id = ?',
                [(int) round($service === 'stt' ? (float) $usage->audioSeconds : 0.0), $sessionId]
            );
        }
    }

    /** @return array{requests:int,cost:float,audio_seconds:float,text:int,stt:int,tts:int} */
    public function userTotalsSince(int $userId, DateTimeImmutable $since): array
    {
        $row = $this->db->one(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(estimated_cost_usd), 0) AS cost,
                    COALESCE(SUM(CASE WHEN service = 'stt' THEN audio_seconds ELSE 0 END), 0) AS audio_seconds,
                    SUM(service = 'text') AS text_count, SUM(service = 'stt') AS stt_count, SUM(service = 'tts') AS tts_count
             FROM api_usage WHERE user_id = ? AND created_at >= ? AND status = 'ok'",
            [$userId, Clock::toSql($since)]
        ) ?? [];
        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
            'audio_seconds' => (float) ($row['audio_seconds'] ?? 0),
            'text' => (int) ($row['text_count'] ?? 0),
            'stt' => (int) ($row['stt_count'] ?? 0),
            'tts' => (int) ($row['tts_count'] ?? 0),
        ];
    }

    /** Total estimated cost of all users since $since (for the monthly budget). */
    public function globalCostSince(DateTimeImmutable $since): float
    {
        return (float) $this->db->scalar('SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM api_usage WHERE created_at >= ?', [Clock::toSql($since)]);
    }

    public function sessionRequests(int $sessionId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM api_usage WHERE session_id = ? AND status = 'ok'", [$sessionId]);
    }

    /** @return list<array<string,mixed>> per-day totals for the last N days */
    public function dailyHistory(int $userId, int $days): array
    {
        return $this->db->all(
            "SELECT DATE(created_at) AS day, service, COUNT(*) AS requests, SUM(estimated_cost_usd) AS cost
             FROM api_usage WHERE user_id = ? AND created_at >= ? AND status = 'ok'
             GROUP BY DATE(created_at), service ORDER BY day",
            [$userId, Clock::toSql($this->clock->now()->modify("-{$days} days"))]
        );
    }
}
