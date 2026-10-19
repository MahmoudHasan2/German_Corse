<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

final class ProviderUsage
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $cachedTokens = null,
        public readonly ?int $reasoningTokens = null,
        public readonly ?float $audioSeconds = null,
        public readonly ?int $characters = null,
        public readonly bool $reportedByProvider = false,
        public readonly array $raw = [],
    ) {
    }
}
