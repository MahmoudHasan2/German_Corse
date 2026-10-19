<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Groq;

use GermanCoach\Ai\EvaluationRequest;
use GermanCoach\Ai\EvaluationResult;
use GermanCoach\Ai\OpenAi\OpenAiClient;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\TutorEvaluator;
use GermanCoach\Ai\TutorPrompt;
use GermanCoach\Evaluation\EvaluationSchema;

/**
 * Answer evaluation through Groq's OpenAI-compatible Chat Completions endpoint
 * (POST {GROQ_BASE_URL}/chat/completions) with Structured Outputs:
 * response_format = {type: json_schema, json_schema: {name, strict: true, schema}}.
 * Strict mode is supported by openai/gpt-oss-20b, openai/gpt-oss-120b and a few others
 * (checked 2026-10-18). The output is validated again by Evaluation::fromArray().
 */
final class GroqTutor implements TutorEvaluator
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $reasoningEffort = 'low',
    ) {
    }

    public function providerName(): string
    {
        return 'groq';
    }

    /** @return array<string,mixed> */
    public function buildPayload(EvaluationRequest $r): array
    {
        $payload = [
            'model' => $r->model,
            'messages' => [
                ['role' => 'system', 'content' => TutorPrompt::instructions()],
                ['role' => 'user', 'content' => TutorPrompt::input($r)],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'answer_evaluation',
                    'strict' => true,
                    'schema' => EvaluationSchema::providerSchema(),
                ],
            ],
            'max_completion_tokens' => $r->maxOutputTokens,
        ];
        // gpt-oss models accept low | medium | high.
        $effort = $this->reasoningEffort === 'minimal' ? 'low' : $this->reasoningEffort;
        if ($effort !== '' && str_starts_with($r->model, 'openai/gpt-oss')) {
            $payload['reasoning_effort'] = $effort;
        }
        return $payload;
    }

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        $response = $this->client->postJson('chat/completions', $this->buildPayload($request));
        $data = $response->json();
        $requestId = $response->header('x-request-id') ?? (is_string($data['id'] ?? null) ? $data['id'] : null);
        if ($data === null) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'non-JSON body', $requestId);
        }
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'no choices', $requestId);
        }
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new ProviderException(ProviderException::INCOMPLETE, $response->status, false, 'finish_reason=length', $requestId);
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        if (is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
            throw new ProviderException(ProviderException::REFUSAL, $response->status, false, mb_substr($message['refusal'], 0, 200), $requestId);
        }
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        if ($text === '') {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'empty content', $requestId);
        }
        $decoded = json_decode($text, true);
        return new EvaluationResult($decoded ?? $text, self::usage($data), (string) ($data['model'] ?? $request->model), $requestId);
    }

    /** @param array<string,mixed> $data */
    public static function usage(array $data): ProviderUsage
    {
        $u = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        if ($u === null) {
            return new ProviderUsage();
        }
        return new ProviderUsage(
            inputTokens: isset($u['prompt_tokens']) ? (int) $u['prompt_tokens'] : null,
            outputTokens: isset($u['completion_tokens']) ? (int) $u['completion_tokens'] : null,
            cachedTokens: isset($u['prompt_tokens_details']['cached_tokens']) ? (int) $u['prompt_tokens_details']['cached_tokens'] : null,
            reasoningTokens: isset($u['completion_tokens_details']['reasoning_tokens']) ? (int) $u['completion_tokens_details']['reasoning_tokens'] : null,
            reportedByProvider: true,
            raw: $u,
        );
    }
}
