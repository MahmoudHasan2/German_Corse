<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Groq;

use GermanCoach\Ai\OpenAi\AudioFormats;
use GermanCoach\Ai\OpenAi\OpenAiClient;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\SpeechToText;
use GermanCoach\Ai\TranscriptionResult;

/**
 * Whisper on Groq: POST {GROQ_BASE_URL}/audio/transcriptions (multipart).
 * response_format=verbose_json returns segments with avg_logprob and no_speech_prob,
 * which we turn into a duration-weighted average probability (confidence signal).
 */
final class GroqTranscriber implements SpeechToText
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $model = 'whisper-large-v3',
        private readonly string $language = 'de',
        private readonly string $prompt = '',
    ) {
    }

    public function providerName(): string
    {
        return 'groq';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function transcribe(string $filePath, string $mimeType, float $durationSeconds): TranscriptionResult
    {
        $fields = [
            'file' => new \CURLFile($filePath, $mimeType, 'answer.' . AudioFormats::extensionFor($mimeType)),
            'model' => $this->model,
            'response_format' => 'verbose_json',
            'temperature' => '0',
        ];
        if ($this->language !== '') {
            $fields['language'] = $this->language;
        }
        if ($this->prompt !== '') {
            $fields['prompt'] = mb_substr($this->prompt, 0, 600);
        }
        $response = $this->client->postMultipart('audio/transcriptions', $fields);
        $data = $response->json();
        $requestId = $response->header('x-request-id');
        if ($data === null || !is_string($data['text'] ?? null)) {
            throw new ProviderException(ProviderException::INVALID_RESPONSE, $response->status, false, 'missing text', $requestId);
        }
        $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
        $text = trim($data['text']);
        if ($segments !== [] && self::allSilence($segments)) {
            $text = '';
        }
        $seconds = is_numeric($data['duration'] ?? null) ? (float) $data['duration'] : $durationSeconds;
        return new TranscriptionResult(
            $text,
            self::averageProbability($segments),
            new ProviderUsage(audioSeconds: $seconds, reportedByProvider: is_numeric($data['duration'] ?? null)),
            $requestId,
        );
    }

    /** @param list<mixed> $segments */
    public static function averageProbability(array $segments): ?float
    {
        $weighted = 0.0;
        $total = 0.0;
        foreach ($segments as $s) {
            if (!is_array($s) || !is_numeric($s['avg_logprob'] ?? null)) {
                continue;
            }
            $w = max(0.1, (float) ($s['end'] ?? 0) - (float) ($s['start'] ?? 0));
            $weighted += (float) $s['avg_logprob'] * $w;
            $total += $w;
        }
        return $total > 0 ? round(exp($weighted / $total), 4) : null;
    }

    /** @param list<mixed> $segments */
    private static function allSilence(array $segments): bool
    {
        foreach ($segments as $s) {
            if (!is_array($s) || (float) ($s['no_speech_prob'] ?? 0) < 0.85) {
                return false;
            }
        }
        return true;
    }
}
