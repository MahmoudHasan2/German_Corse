<?php

declare(strict_types=1);

namespace GermanCoach\Audio;

use GermanCoach\Core\HttpException;

/**
 * Validates an uploaded recording. The client's filename, extension and MIME type are
 * ignored: the type is detected from the file content (finfo) and checked against an
 * allow-list of formats accepted by the transcription endpoint.
 */
final class AudioUploadValidator
{
    /** finfo results we accept (WebM/Ogg are often reported as video/*). */
    public const ALLOWED_MIME = [
        'audio/webm', 'video/webm',
        'audio/ogg', 'video/ogg', 'audio/opus',
        'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac',
        'audio/mpeg', 'audio/mp3',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
    ];
    public const MIN_BYTES = 600;

    /** @var \Closure(string):bool */
    private \Closure $isUploaded;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxSeconds,
        ?\Closure $isUploaded = null,
    ) {
        $this->isUploaded = $isUploaded ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     * @return array{mime:string,bytes:int,seconds:float}
     */
    public function validate(?array $file, ?int $clientDurationMs): array
    {
        if ($file === null) {
            throw self::fail('audio_missing', 'No recording was uploaded.');
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw self::fail('audio_too_large', 'The recording is too large.', 413);
            case UPLOAD_ERR_PARTIAL:
                throw self::fail('audio_partial', 'The upload was interrupted. Please try again.');
            case UPLOAD_ERR_NO_FILE:
                throw self::fail('audio_missing', 'No recording was uploaded.');
            default:
                throw self::fail('audio_upload_failed', 'The server could not store the upload.', 500);
        }
        $path = $file['tmp_name'];
        if ($path === '' || !($this->isUploaded)($path) || !is_file($path)) {
            throw self::fail('audio_invalid', 'Invalid upload.');
        }
        $bytes = (int) filesize($path);
        if ($bytes > $this->maxBytes) {
            throw self::fail('audio_too_large', 'The recording is too large.', 413);
        }
        if ($bytes < self::MIN_BYTES) {
            throw self::fail('audio_empty', 'The recording is empty or too short.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw self::fail('audio_unsupported_type', 'Unsupported audio format.', 415);
        }

        $client = $clientDurationMs !== null ? max(0, $clientDurationMs) / 1000 : 0.0;
        if ($client > $this->maxSeconds + 2) {
            throw self::fail('audio_too_long', 'The recording is longer than allowed.');
        }
        // Lower bound from size (≤128 kbit/s), so an understated client duration cannot hide usage.
        $sizeBound = $bytes / 16000;
        $seconds = min((float) $this->maxSeconds, max($client, $sizeBound, 0.5));
        return ['mime' => $mime, 'bytes' => $bytes, 'seconds' => round($seconds, 2)];
    }

    private static function fail(string $code, string $message, int $status = 422): HttpException
    {
        return new HttpException($status, $code, $message);
    }
}
