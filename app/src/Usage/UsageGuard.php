<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

use DateTimeImmutable;
use GermanCoach\Ai\ProviderFactory;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Config;
use GermanCoach\Core\Logger;

/**
 * Decides — before any paid call — whether an AI request may be made.
 * Order: global switches → monthly emergency threshold → per-user daily limits →
 * per-session limit → per-minute rate limit. Days and months are counted in UTC.
 *
 * Note: concurrent requests can overshoot a limit by at most one request each;
 * a provider-side budget (OpenAI dashboard) remains the final safeguard.
 */
final class UsageGuard
{
    public const KILL_SWITCH = 'ai_kill_switch';
    public const EMERGENCY_MARKER = 'ai_emergency_shutdown_at';

    public function __construct(
        private readonly Config $config,
        private readonly UsageRepository $usage,
        private readonly AppSettings $settings,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ProviderFactory $providers,
    ) {
    }

    public function check(int $userId, ?int $sessionId, string $service, float $estimatedCost, float $audioSeconds = 0.0, bool $consumeRateLimit = true): UsageDecision
    {
        $snapshot = $this->snapshot($userId);

        if (!$this->config->bool('AI_ENABLED', true) || $this->settings->get(self::KILL_SWITCH) === '1') {
            return new UsageDecision(false, 'ai_disabled', false, $snapshot);
        }
        if (!$this->providers->isConfigured($service)) {
            return new UsageDecision(false, 'ai_not_configured', false, $snapshot);
        }

        $monthlyBudget = $this->config->float('LIMIT_MONTHLY_BUDGET_USD', 10.0);
        $threshold = $monthlyBudget * min(1.0, max(0.1, $this->config->float('LIMIT_EMERGENCY_THRESHOLD', 0.95)));
        if ($snapshot['month_cost'] + $estimatedCost >= $threshold) {
            if ($this->settings->get(self::EMERGENCY_MARKER) === null) {
                $this->settings->set(self::EMERGENCY_MARKER, $this->clock->nowSql());
                $this->logger->error('ai_emergency_shutdown', ['month_cost' => $snapshot['month_cost'], 'threshold' => $threshold]);
            }
            return new UsageDecision(false, 'monthly_budget_reached', true, $snapshot);
        }

        if ($snapshot['today_requests'] >= $snapshot['limits']['daily_requests']) {
            return new UsageDecision(false, 'daily_request_limit', true, $snapshot);
        }
        if ($snapshot['today_cost'] + $estimatedCost > $snapshot['limits']['daily_budget_usd']) {
            return new UsageDecision(false, 'daily_budget_reached', true, $snapshot);
        }
        if ($service === 'stt' && ($snapshot['today_audio_seconds'] + $audioSeconds) > $snapshot['limits']['daily_audio_minutes'] * 60) {
            return new UsageDecision(false, 'daily_audio_limit', true, $snapshot);
        }
        if ($sessionId !== null && $this->usage->sessionRequests($sessionId) >= $this->config->int('LIMIT_SESSION_REQUESTS', 100)) {
            return new UsageDecision(false, 'session_request_limit', true, $snapshot);
        }
        if ($consumeRateLimit) {
            $rate = $this->limiter->hit('ai_user:' . $userId, max(1, $this->config->int('RATE_AI_PER_MINUTE', 15)), 60);
            if (!$rate->allowed) {
                return new UsageDecision(false, 'rate_limited', false, $snapshot, $rate->retryAfterSeconds);
            }
        }

        return new UsageDecision(true, null, $snapshot['warning'], $snapshot);
    }

    /** @return array<string,mixed> current usage vs. limits (also shown on the usage page) */
    public function snapshot(int $userId): array
    {
        $now = $this->clock->now();
        $dayStart = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', $now->getTimezone());
        $monthStart = new DateTimeImmutable($now->format('Y-m-01') . ' 00:00:00', $now->getTimezone());
        $today = $this->usage->userTotalsSince($userId, $dayStart);
        $monthCost = $this->usage->globalCostSince($monthStart);

        $limits = [
            'daily_requests' => max(0, $this->config->int('LIMIT_DAILY_REQUESTS', 250)),
            'daily_audio_minutes' => max(0.0, $this->config->float('LIMIT_DAILY_AUDIO_MINUTES', 30)),
            'daily_budget_usd' => max(0.0, $this->config->float('LIMIT_USER_DAILY_BUDGET_USD', 0.5)),
            'monthly_budget_usd' => max(0.0, $this->config->float('LIMIT_MONTHLY_BUDGET_USD', 10)),
            'emergency_threshold' => $this->config->float('LIMIT_EMERGENCY_THRESHOLD', 0.95),
            'session_requests' => $this->config->int('LIMIT_SESSION_REQUESTS', 100),
            'max_recording_seconds' => $this->config->int('AUDIO_MAX_SECONDS', 60),
        ];
        $ratios = [
            'requests' => $limits['daily_requests'] > 0 ? $today['requests'] / $limits['daily_requests'] : 1.0,
            'audio' => $limits['daily_audio_minutes'] > 0 ? $today['audio_seconds'] / ($limits['daily_audio_minutes'] * 60) : 1.0,
            'daily_cost' => $limits['daily_budget_usd'] > 0 ? $today['cost'] / $limits['daily_budget_usd'] : 1.0,
            'monthly_cost' => $limits['monthly_budget_usd'] > 0 ? $monthCost / ($limits['monthly_budget_usd'] * $limits['emergency_threshold']) : 1.0,
        ];
        $warnRatio = $this->config->float('LIMIT_WARNING_RATIO', 0.8);

        return [
            'today_requests' => $today['requests'],
            'today_text_requests' => $today['text'],
            'today_stt_requests' => $today['stt'],
            'today_tts_requests' => $today['tts'],
            'today_cost' => round($today['cost'], 6),
            'today_audio_seconds' => round($today['audio_seconds'], 1),
            'month_cost' => round($monthCost, 6),
            'limits' => $limits,
            'ratios' => array_map(static fn (float $r) => round($r, 3), $ratios),
            'warning' => max($ratios) >= $warnRatio,
            'ai_enabled' => $this->config->bool('AI_ENABLED', true) && $this->settings->get(self::KILL_SWITCH) !== '1',
            'provider' => $this->providers->provider('tutor'),
            'provider_configured' => $this->providers->isConfigured('tutor'),
            'stt_provider' => $this->providers->provider('stt'),
            'tts_provider' => $this->providers->provider('tts'),
        ];
    }
}
