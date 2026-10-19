<?php

declare(strict_types=1);

namespace GermanCoach\Ai\OpenAi;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\SpeechToText;
use GermanCoach\Ai\TranscriptionResult;

/**
 * POST /v1/audio/transcriptions (multipart). For gpt-4o(-mini)-transcribe only
 * response_format=json is supported; include[]=logprobs gives token log-probabilities,
 * which we turn into an average probability as a transcription-confidence signal.
 */
final class OpenAiTranscriber implements SpeechToText
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $model,
        private readonly string $language = 'de',
        private readonly string $prompt = '',
        private readonly bool $logprobs = true,
    ) {
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function transcribe(string $filePath, string $mimeType, float $durationSeconds): TranscriptionResult
    {
        $ext = AudioFormats::extensionFor($mimeType);
        $fields = [
            'file' => new \CURLFile($filePath, $mimeType, 'answer.' . $ext),
            'model' => $this->model,
            'response_format' => 'json',
        ];
        if ($this->language !== '') {
            $fields['language'] = $this->language;
        }
        if ($this->prompt !== '') {
            $fields['prompt'] = $this->prompt;
        }
        if ($this->logprobs && !str_starts_with($this->model, 'whisper')) {
            $fields['include[]'] = 'logprobs';
        }

        $response = $this->client->postMultipart('audio/transcriptions', $fields);
        $data = $response->json();
        $requestId = $response->header('x-request-id');
        if ($data === null || !is_string($data['text'] ?? null)) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'missing text', $requestId);
        }
        return new TranscriptionResult(
            trim($data['text']),
            self::averageProbability($data['logprobs'] ?? null),
            self::usage($data, $durationSeconds),
            $requestId,
        );
    }

    public static function averageProbability(mixed $logprobs): ?float
    {
        if (!is_array($logprobs) || $logprobs === []) {
            return null;
        }
        $sum = 0.0;
        $n = 0;
        foreach ($logprobs as $lp) {
            if (is_array($lp) && is_numeric($lp['logprob'] ?? null)) {
                $sum += (float) $lp['logprob'];
                $n++;
            }
        }
        return $n === 0 ? null : round(exp($sum / $n), 4);
    }

    /** @param array<string,mixed> $data */
    public static function usage(array $data, float $clientSeconds): ProviderUsage
    {
        $u = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        if (($u['type'] ?? null) === 'tokens') {
            return new ProviderUsage(
                inputTokens: (int) ($u['input_tokens'] ?? 0),
                outputTokens: (int) ($u['output_tokens'] ?? 0),
                audioSeconds: $clientSeconds,
                reportedByProvider: true,
                raw: $u,
            );
        }
        if (($u['type'] ?? null) === 'duration') {
            return new ProviderUsage(audioSeconds: (float) ($u['seconds'] ?? $clientSeconds), reportedByProvider: true, raw: $u);
        }
        return new ProviderUsage(audioSeconds: $clientSeconds);
    }
}
