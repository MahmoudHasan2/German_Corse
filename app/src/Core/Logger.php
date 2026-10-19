<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * JSON-lines logger writing to storage/logs (outside the document root).
 * Sensitive keys are redacted recursively before writing.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
    private const SENSITIVE = ['password', 'password_hash', 'api_key', 'authorization', 'token', 'secret', 'cookie', 'openai_api_key', 'setup_token', 'csrf'];

    private string $requestId;
    /** @var list<array<string,mixed>> */
    private array $memory = [];

    public function __construct(
        private readonly ?string $directory,
        private readonly string $minLevel = 'info',
    ) {
        $this->requestId = bin2hex(random_bytes(8));
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $entry = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'request_id' => $this->requestId,
            'message' => $message,
            'context' => self::redact($context),
        ];
        if ($this->directory === null) {
            $this->memory[] = $entry;
            return;
        }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        $file = rtrim($this->directory, '/\\') . '/app-' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * In-memory entries (only when constructed without a directory, e.g. tests).
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        return $this->memory;
    }

    /** @param array<mixed> $data @return array<mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (self::SENSITIVE as $s) {
                    if (str_contains($lower, $s)) {
                        $data[$key] = '[redacted]';
                        continue 2;
                    }
                }
            }
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($value)) {
                // Never let an API key slip through inside free text.
                $data[$key] = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-[redacted]', $value);
            } elseif ($value instanceof \Throwable) {
                $data[$key] = ['class' => $value::class, 'message' => preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-[redacted]', $value->getMessage()), 'file' => basename($value->getFile()) . ':' . $value->getLine()];
            }
        }
        return $data;
    }
}
