<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

interface TutorEvaluator
{
    /** @throws ProviderException */
    public function evaluate(EvaluationRequest $request): EvaluationResult;

    public function providerName(): string;
}
