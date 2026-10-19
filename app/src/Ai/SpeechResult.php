<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

final class SpeechResult
{
    public function __construct(
        public readonly string $audio,
        public readonly string $mimeType,
        public readonly ProviderUsage $usage,
        public readonly ?string $providerRequestId,
    ) {
    }
}
