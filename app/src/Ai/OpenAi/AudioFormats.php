<?php

declare(strict_types=1);

namespace GermanCoach\Ai\OpenAi;

final class AudioFormats
{
    /** Server-detected MIME type → extension accepted by the transcription endpoint. */
    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'audio/webm', 'video/webm' => 'webm',
            'audio/ogg', 'video/ogg', 'audio/opus' => 'ogg',
            'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac' => 'm4a',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'wav',
            default => 'webm',
        };
    }

    public static function mimeForTtsFormat(string $format): string
    {
        return match ($format) {
            'opus' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'pcm' => 'audio/L16',
            default => 'audio/mpeg',
        };
    }
}
