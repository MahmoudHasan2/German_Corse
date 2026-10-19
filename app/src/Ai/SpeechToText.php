<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

interface SpeechToText
{
    /** @throws ProviderException */
    public function transcribe(string $filePath, string $mimeType, float $durationSeconds): TranscriptionResult;

    public function providerName(): string;

    public function model(): string;
}
