<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Fake;

use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\SpeechResult;
use GermanCoach\Ai\TextToSpeech;

/**
 * Text-to-speech stand-in: returns a short, valid silent WAV file so the audio player
 * works end-to-end without a provider. The demo UI offers the browser voice instead.
 */
final class FakeSpeech implements TextToSpeech
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function providerName(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-tts';
    }

    public function cacheKeyPrefix(): string
    {
        return 'fake|wav';
    }

    public function mimeType(): string
    {
        return 'audio/wav';
    }

    public function synthesize(string $text): SpeechResult
    {
        self::$calls++;
        return new SpeechResult(self::silentWav(0.3), 'audio/wav', new ProviderUsage(characters: mb_strlen($text)), 'fake-tts-' . self::$calls);
    }

    public static function silentWav(float $seconds): string
    {
        $rate = 8000;
        $samples = (int) ($rate * $seconds);
        $data = str_repeat("\x80", $samples); // 8-bit unsigned PCM silence
        return 'RIFF' . pack('V', 36 + $samples) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8)
            . 'data' . pack('V', $samples) . $data;
    }
}
