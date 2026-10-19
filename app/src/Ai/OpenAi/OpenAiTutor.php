<?php

declare(strict_types=1);

namespace GermanCoach\Ai\OpenAi;

use GermanCoach\Ai\EvaluationRequest;
use GermanCoach\Ai\EvaluationResult;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\TutorEvaluator;
use GermanCoach\Ai\TutorPrompt;
use GermanCoach\Evaluation\EvaluationSchema;

/**
 * Answer evaluation through the OpenAI Responses API (POST /v1/responses) with
 * Structured Outputs: text.format = {type: json_schema, strict: true}.
 * The returned JSON is still validated again by Evaluation::fromArray().
 */
final class OpenAiTutor implements TutorEvaluator
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $reasoningEffort = 'low',
    ) {
    }

    public function providerName(): string
    {
        return 'openai';
    }

    /** @return array<string,mixed> */
    public function buildPayload(EvaluationRequest $r): array
    {
        $payload = [
            'model' => $r->model,
            'instructions' => TutorPrompt::instructions(),
            'input' => [[
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => TutorPrompt::input($r)]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'answer_evaluation',
                    'schema' => EvaluationSchema::providerSchema(),
                    'strict' => true,
                ],
            ],
            'max_output_tokens' => $r->maxOutputTokens,
            'store' => false,
            'safety_identifier' => $r->safetyIdentifier,
        ];
        if ($this->reasoningEffort !== '') {
            $payload['reasoning'] = ['effort' => $this->reasoningEffort];
        }
        return $payload;
    }

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        $response = $this->client->postJson('responses', $this->buildPayload($request));
        $data = $response->json();
        $requestId = $response->header('x-request-id') ?? (is_string($data['id'] ?? null) ? $data['id'] : null);
        if ($data === null) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'non-JSON body', $requestId);
        }
        $usage = self::usage($data);

        if (($data['status'] ?? null) === 'incomplete') {
            $reason = (string) ($data['incomplete_details']['reason'] ?? 'unknown');
            throw new ProviderException(ProviderException::INCOMPLETE, $response->status, false, 'incomplete: ' . $reason, $requestId);
        }
        if (is_array($data['error'] ?? null)) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, (string) ($data['error']['message'] ?? 'error'), $requestId);
        }

        $text = null;
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue; // e.g. reasoning items
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw new ProviderException(ProviderException::REFUSAL, $response->status, false, mb_substr((string) ($part['refusal'] ?? ''), 0, 200), $requestId);
                }
                if (($part['type'] ?? null) === 'output_text') {
                    $text = (string) ($part['text'] ?? '');
                }
            }
        }
        if ($text === null || $text === '') {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'no output_text', $requestId);
        }
        $decoded = json_decode($text, true);
        return new EvaluationResult($decoded ?? $text, $usage, (string) ($data['model'] ?? $request->model), $requestId);
    }

    /** @param array<string,mixed> $data */
    public static function usage(array $data): ProviderUsage
    {
        $u = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        if ($u === null) {
            return new ProviderUsage();
        }
        return new ProviderUsage(
            inputTokens: isset($u['input_tokens']) ? (int) $u['input_tokens'] : null,
            outputTokens: isset($u['output_tokens']) ? (int) $u['output_tokens'] : null,
            cachedTokens: isset($u['input_tokens_details']['cached_tokens']) ? (int) $u['input_tokens_details']['cached_tokens'] : null,
            reasoningTokens: isset($u['output_tokens_details']['reasoning_tokens']) ? (int) $u['output_tokens_details']['reasoning_tokens'] : null,
            reportedByProvider: true,
            raw: $u,
        );
    }
}
