<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

use GermanCoach\Ai\ProviderUsage;

/** Estimates USD cost of provider calls from usage numbers and the pricing table. */
final class CostCalculator
{
    public function __construct(private readonly PricingTable $pricing)
    {
    }

    public function text(string $model, ProviderUsage $u): float
    {
        $r = $this->pricing->rates($model, 'text');
        $input = (int) ($u->inputTokens ?? 0);
        $cached = min($input, (int) ($u->cachedTokens ?? 0));
        $output = (int) ($u->outputTokens ?? 0); // reasoning tokens are included in output tokens
        return (($input - $cached) * ($r['input_per_1m'] ?? 0)
            + $cached * ($r['cached_input_per_1m'] ?? ($r['input_per_1m'] ?? 0))
            + $output * ($r['output_per_1m'] ?? 0)) / 1_000_000;
    }

    /** Worst-case estimate before a text call: prompt size plus the full output budget. */
    public function estimateText(string $model, int $promptChars, int $maxOutputTokens): float
    {
        return $this->text($model, new ProviderUsage(inputTokens: (int) ceil($promptChars / 3), outputTokens: $maxOutputTokens));
    }

    public function stt(string $model, ProviderUsage $u): float
    {
        $r = $this->pricing->rates($model, 'stt');
        if ($u->inputTokens !== null && isset($r['input_per_1m'])) {
            return ($u->inputTokens * $r['input_per_1m'] + (int) $u->outputTokens * ($r['output_per_1m'] ?? 0)) / 1_000_000;
        }
        return (float) ($u->audioSeconds ?? 0) / 60 * ($r['per_minute'] ?? 0);
    }

    public function estimateStt(string $model, float $seconds): float
    {
        $r = $this->pricing->rates($model, 'stt');
        $perMinute = $r['per_minute'] ?? (isset($r['input_per_1m']) ? 0.01 : 0.01);
        return $seconds / 60 * $perMinute;
    }

    public function tts(string $model, int $characters): float
    {
        $r = $this->pricing->rates($model, 'tts');
        if (isset($r['per_1m_chars'])) {
            return $characters * $r['per_1m_chars'] / 1_000_000;
        }
        // Token-priced TTS returns no usage: estimate spoken minutes from characters.
        $minutes = $characters / max(1.0, $r['chars_per_minute'] ?? 750.0);
        $textCost = ceil($characters / 4) * ($r['text_input_per_1m'] ?? 0) / 1_000_000;
        return $minutes * ($r['estimated_per_minute'] ?? 0.015) + $textCost;
    }
}
