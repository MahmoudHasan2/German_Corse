<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

final class TranscriptionResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?float $averageProbability,
        public readonly ProviderUsage $usage,
        public readonly ?string $providerRequestId,
    ) {
    }
}
