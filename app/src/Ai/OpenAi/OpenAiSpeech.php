<?php

declare(strict_types=1);

namespace GermanCoach\Ai\OpenAi;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\SpeechResult;
use GermanCoach\Ai\TextToSpeech;

/**
 * POST /v1/audio/speech → binary audio. Input is limited to 4096 characters by the API.
 * "instructions" is only sent for models that support it (not tts-1 / tts-1-hd).
 */
final class OpenAiSpeech implements TextToSpeech
{
    public const MAX_INPUT_CHARS = 4096;

    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $model,
        private readonly string $voice,
        private readonly string $format = 'mp3',
        private readonly float $speed = 1.0,
        private readonly string $instructions = '',
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

    public function cacheKeyPrefix(): string
    {
        return implode('|', ['openai', $this->model, $this->voice, $this->format, (string) $this->speed, $this->supportsInstructions() ? $this->instructions : '']);
    }

    public function mimeType(): string
    {
        return AudioFormats::mimeForTtsFormat($this->format);
    }

    /** @return array<string,mixed> */
    public function buildPayload(string $text): array
    {
        $payload = [
            'model' => $this->model,
            'input' => mb_substr($text, 0, self::MAX_INPUT_CHARS),
            'voice' => $this->voice,
            'response_format' => $this->format,
            'speed' => max(0.25, min(4.0, $this->speed)),
        ];
        if ($this->supportsInstructions() && $this->instructions !== '') {
            $payload['instructions'] = $this->instructions;
        }
        return $payload;
    }

    public function synthesize(string $text): SpeechResult
    {
        $response = $this->client->postJson('audio/speech', $this->buildPayload($text));
        if ($response->body === '' || str_starts_with(ltrim($response->body), '{')) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'expected binary audio', $response->header('x-request-id'));
        }
        return new SpeechResult(
            $response->body,
            $this->mimeType(),
            new ProviderUsage(characters: mb_strlen($text)),
            $response->header('x-request-id'),
        );
    }

    private function supportsInstructions(): bool
    {
        return !in_array($this->model, ['tts-1', 'tts-1-hd'], true);
    }
}
