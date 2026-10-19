<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Gemini;

use GermanCoach\Ai\EvaluationRequest;
use GermanCoach\Ai\EvaluationResult;
use GermanCoach\Ai\OpenAi\OpenAiClient;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\TutorEvaluator;
use GermanCoach\Ai\TutorPrompt;
use GermanCoach\Evaluation\EvaluationSchema;

/**
 * Answer evaluation through the Gemini API:
 * POST {GEMINI_BASE_URL}/models/{model}:generateContent (header x-goog-api-key)
 * with generationConfig.responseMimeType = application/json and a JSON schema.
 * The output is validated again by Evaluation::fromArray().
 */
final class GeminiTutor implements TutorEvaluator
{
    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    /** @return array<string,mixed> */
    public function buildPayload(EvaluationRequest $r): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => TutorPrompt::instructions()]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => TutorPrompt::input($r)]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => EvaluationSchema::providerSchema(),
                'maxOutputTokens' => $r->maxOutputTokens,
            ],
        ];
    }

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        if (!preg_match('/^[a-z0-9.\-]+$/', $request->model)) {
            throw new ProviderException(ProviderException::NOT_CONFIGURED, null, false, 'invalid Gemini model id');
        }
        $response = $this->client->postJson('models/' . $request->model . ':generateContent', $this->buildPayload($request));
        $data = $response->json();
        $requestId = is_string($data['responseId'] ?? null) ? $data['responseId'] : $response->header('x-request-id');
        if ($data === null) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'non-JSON body', $requestId);
        }
        if (is_string($data['promptFeedback']['blockReason'] ?? null)) {
            throw new ProviderException(ProviderException::REFUSAL, $response->status, false, 'blocked: ' . $data['promptFeedback']['blockReason'], $requestId);
        }
        $candidate = $data['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'no candidates', $requestId);
        }
        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if ($finish === 'MAX_TOKENS' || $finish === 'CONTINUATION') {
            throw new ProviderException(ProviderException::INCOMPLETE, $response->status, false, 'finishReason=' . $finish, $requestId);
        }
        if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true)) {
            throw new ProviderException(ProviderException::REFUSAL, $response->status, false, 'finishReason=' . $finish, $requestId);
        }
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'empty text, finishReason=' . $finish, $requestId);
        }
        $decoded = json_decode($text, true);
        return new EvaluationResult($decoded ?? $text, self::usage($data), (string) ($data['modelVersion'] ?? $request->model), $requestId);
    }

    /** @param array<string,mixed> $data */
    public static function usage(array $data): ProviderUsage
    {
        $u = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : null;
        if ($u === null) {
            return new ProviderUsage();
        }
        $thoughts = (int) ($u['thoughtsTokenCount'] ?? 0);
        return new ProviderUsage(
            inputTokens: isset($u['promptTokenCount']) ? (int) $u['promptTokenCount'] : null,
            outputTokens: (int) ($u['candidatesTokenCount'] ?? 0) + $thoughts,
            cachedTokens: isset($u['cachedContentTokenCount']) ? (int) $u['cachedContentTokenCount'] : null,
            reasoningTokens: $thoughts > 0 ? $thoughts : null,
            reportedByProvider: true,
            raw: $u,
        );
    }
}
