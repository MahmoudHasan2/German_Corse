<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

use GermanCoach\Evaluation\ExerciseItem;

final class EvaluationRequest
{
    /** @param list<array{answer:string,verdict:string,categories:list<string>}> $previousAttempts */
    public function __construct(
        public readonly ExerciseItem $item,
        public readonly string $answer,
        public readonly string $inputMode,
        public readonly string $transcriptionConfidence,
        public readonly int $attemptNumber,
        public readonly int $strategyLevel,
        public readonly array $previousAttempts,
        public readonly string $model,
        public readonly int $maxOutputTokens,
        public readonly string $safetyIdentifier,
    ) {
    }
}
