<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Ai\EvaluationRequest;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderFactory;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\TutorPrompt;
use GermanCoach\Core\Config;
use GermanCoach\Core\Logger;
use GermanCoach\Evaluation\Evaluation;
use GermanCoach\Evaluation\ExerciseItem;
use GermanCoach\Evaluation\InvalidEvaluationException;
use GermanCoach\Evaluation\PreEvaluator;
use GermanCoach\Usage\CostCalculator;
use GermanCoach\Usage\UsageDecision;
use GermanCoach\Usage\UsageGuard;
use GermanCoach\Usage\UsageRepository;

/**
 * Evaluates one answer as cheaply as possible:
 *   1. deterministic rule check (exact reference match / incomplete) — free;
 *   2. usage guard — blocks the paid call when a limit is reached (offline fallback);
 *   3. provider call, strict validation, usage + cost accounting.
 */
final class AnswerEvaluator
{
    private ?UsageDecision $lastUsage = null;

    public function __construct(
        private readonly PreEvaluator $pre,
        private readonly UsageGuard $guard,
        private readonly UsageRepository $usage,
        private readonly CostCalculator $costs,
        private readonly ProviderFactory $providers,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<array{answer:string,verdict:string,outcome:string,categories:list<string>}> $history
     * @throws ProviderException when the provider fails (caller restores session state)
     */
    public function evaluate(
        int $userId,
        int $sessionId,
        ExerciseItem $item,
        string $answer,
        string $inputMode,
        string $transcriptionConfidence,
        int $attemptNumber,
        int $strategyLevel,
        array $history,
    ): Evaluation {
        $this->lastUsage = null;
        $rule = $this->pre->evaluate($item, $answer);
        if ($rule !== null) {
            return $rule;
        }

        $model = $this->providers->tutorModel($item->skillArea, $answer);
        $maxTokens = max(300, min(8000, $this->config->int('TUTOR_MAX_OUTPUT_TOKENS', 2000)));
        $request = new EvaluationRequest(
            $item,
            $answer,
            $inputMode,
            $transcriptionConfidence,
            $attemptNumber,
            $strategyLevel,
            array_map(static fn ($h) => ['answer' => $h['answer'], 'verdict' => $h['verdict'], 'categories' => $h['categories']], array_slice($history, -2)),
            $model,
            $maxTokens,
            substr(hash('sha256', 'gac-user-' . $userId), 0, 32),
        );
        $estimate = $this->costs->estimateText($model, mb_strlen(TutorPrompt::instructions() . TutorPrompt::input($request)), $maxTokens);

        $decision = $this->guard->check($userId, $sessionId, 'text', $estimate);
        $this->lastUsage = $decision;
        if (!$decision->allowed) {
            return Evaluation::offline($decision->messageAr());
        }

        $tutor = $this->providers->tutor();
        $started = microtime(true);
        try {
            $result = $tutor->evaluate($request);
        } catch (ProviderException $e) {
            $this->usage->record($userId, $sessionId, $tutor->providerName(), 'text', $model, 'error', new ProviderUsage(), 0.0, self::ms($started), $e->errorCode, $e->providerRequestId);
            $this->logger->warning('tutor_provider_error', ['code' => $e->errorCode, 'status' => $e->httpStatus, 'detail' => $e->providerMessage]);
            throw $e;
        }
        $usage = $result->usage;
        $cost = $usage->reportedByProvider ? $this->costs->text($result->model, $usage) : $estimate;
        $this->usage->record($userId, $sessionId, $tutor->providerName(), 'text', $result->model, 'ok', $usage, $cost, self::ms($started), null, $result->providerRequestId);

        try {
            return Evaluation::fromArray($result->data, 'ai');
        } catch (InvalidEvaluationException $e) {
            // Never trust or partially apply malformed output; the attempt is not graded.
            $this->logger->warning('tutor_invalid_output', ['error' => $e->getMessage(), 'model' => $result->model]);
            return new Evaluation('uncertain', false, false, false, 'acceptable', false, null, [], null, 'تعذّر تقييم الإجابة بشكل موثوق هذه المرة، ولن تُحتسب المحاولة. حاول مرة أخرى.', 0.0, 'ai');
        }
    }

    public function lastUsageDecision(): ?UsageDecision
    {
        return $this->lastUsage;
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
