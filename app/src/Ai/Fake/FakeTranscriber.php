<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Fake;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\SpeechToText;
use GermanCoach\Ai\TranscriptionResult;

/** Deterministic speech-to-text stand-in. Tests queue transcripts; default demo text otherwise. */
final class FakeTranscriber implements SpeechToText
{
    /** @var list<array{text:string,probability:?float}|ProviderException> */
    private static array $queue = [];
    public static int $calls = 0;
    /** @var list<string> paths that existed when transcribe() was called */
    public static array $seenFiles = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$calls = 0;
        self::$seenFiles = [];
    }

    public static function queue(string $text, ?float $probability = 0.95): void
    {
        self::$queue[] = ['text' => $text, 'probability' => $probability];
    }

    public static function queueError(ProviderException $e): void
    {
        self::$queue[] = $e;
    }

    public function providerName(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-stt';
    }

    public function transcribe(string $filePath, string $mimeType, float $durationSeconds): TranscriptionResult
    {
        self::$calls++;
        if (is_file($filePath)) {
            self::$seenFiles[] = $filePath;
        }
        $next = array_shift(self::$queue) ?? ['text' => '(Demo) Ich heiße Ali.', 'probability' => 0.9];
        if ($next instanceof ProviderException) {
            throw $next;
        }
        return new TranscriptionResult($next['text'], $next['probability'], new ProviderUsage(audioSeconds: $durationSeconds, reportedByProvider: true), 'fake-stt-' . self::$calls);
    }
}
