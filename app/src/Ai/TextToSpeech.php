<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

interface TextToSpeech
{
    /** @throws ProviderException */
    public function synthesize(string $text): SpeechResult;

    public function providerName(): string;

    /** Identifies voice/model/format so cached audio is invalidated when settings change. */
    public function cacheKeyPrefix(): string;

    public function mimeType(): string;

    public function model(): string;
}
