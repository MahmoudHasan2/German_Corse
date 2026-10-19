<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

final class EvaluationResult
{
    /** @param mixed $data decoded but NOT yet validated model output */
    public function __construct(
        public readonly mixed $data,
        public readonly ProviderUsage $usage,
        public readonly string $model,
        public readonly ?string $providerRequestId,
    ) {
    }
}
