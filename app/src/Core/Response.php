<?php

declare(strict_types=1);

namespace GermanCoach\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        /** Absolute path of a file to stream instead of $body. */
        public ?string $file = null,
    ) {
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );
        return new self($status, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string,mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = [], string $requestId = ''): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        if ($requestId !== '') {
            $error['request_id'] = $requestId;
        }
        return self::json(['ok' => false, 'error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function file(string $path, string $mime, int $maxAge = 0): self
    {
        return new self(200, '', [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => $maxAge > 0 ? 'private, max-age=' . $maxAge : 'no-store',
        ], $path);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function decoded(): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : [];
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
