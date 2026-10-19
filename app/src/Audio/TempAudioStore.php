<?php

declare(strict_types=1);

namespace GermanCoach\Audio;

use GermanCoach\Ai\OpenAi\AudioFormats;

/**
 * Temporary recordings in storage/tmp/audio (outside the web root), under random
 * server-generated names. Files are deleted right after transcription; leftovers from
 * crashed requests are purged lazily.
 */
final class TempAudioStore
{
    /** @var \Closure(string,string):bool */
    private \Closure $mover;

    public function __construct(private readonly string $directory, ?\Closure $mover = null)
    {
        $this->mover = $mover ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
    }

    public function store(string $uploadedPath, string $mime): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Audio temp directory is not writable.');
        }
        $target = rtrim($this->directory, '/\\') . '/' . bin2hex(random_bytes(16)) . '.' . AudioFormats::extensionFor($mime);
        if (!($this->mover)($uploadedPath, $target)) {
            throw new \RuntimeException('Could not move the uploaded recording.');
        }
        @chmod($target, 0640);
        return $target;
    }

    public function delete(string $path): void
    {
        $real = realpath($path);
        $dir = realpath($this->directory);
        if ($real !== false && $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR) && is_file($real)) {
            @unlink($real);
        }
    }

    /** Removes recordings older than $seconds (default 1 hour). */
    public function purgeOlderThan(int $seconds = 3600): int
    {
        $n = 0;
        foreach (glob(rtrim($this->directory, '/\\') . '/*') ?: [] as $file) {
            if (!preg_match('/^[a-f0-9]{32}\.(webm|ogg|m4a|mp3|wav)$/', basename($file))) {
                continue; // never touch .htaccess/.gitignore or anything we did not create
            }
            if (is_file($file) && filemtime($file) < time() - $seconds) {
                @unlink($file);
                $n++;
            }
        }
        return $n;
    }
}
