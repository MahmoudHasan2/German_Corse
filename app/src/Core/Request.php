<?php

declare(strict_types=1);

namespace GermanCoach\Core;

final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];
    /** @var array<string,mixed>|null */
    private ?array $json = null;
    private bool $jsonParsed = false;

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-case header names
     * @param array<string,mixed>  $post
     * @param array<string,mixed>  $files normalized $_FILES
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Support installs in a sub-directory: strip the script directory prefix.
        $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir)) ?: '/';
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $isMultipart = str_contains($headers['content-type'] ?? '', 'multipart/form-data');
        $body = $isMultipart ? '' : (string) file_get_contents('php://input', false, null, 0, 1_048_576);

        /** @var array<string,string> $query */
        $query = array_map(static fn ($v) => is_string($v) ? $v : '', $_GET);

        return new self($method, $path, $query, $headers, $body, $_POST, self::normalizeFiles($_FILES), $_COOKIE, $_SERVER);
    }

    /**
     * @param array<string,mixed> $files
     * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private static function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $field => $file) {
            if (!is_array($file) || is_array($file['name'] ?? null)) {
                continue; // multi-file fields are not used by this app
            }
            $out[(string) $field] = [
                'name' => (string) ($file['name'] ?? ''),
                'type' => (string) ($file['type'] ?? ''),
                'tmp_name' => (string) ($file['tmp_name'] ?? ''),
                'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($file['size'] ?? 0),
            ];
        }
        return $out;
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            if ($this->body !== '') {
                try {
                    $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new HttpException(400, 'invalid_json', 'Request body is not valid JSON.');
                }
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'invalid_json', 'Request body must be a JSON object.');
                }
                $this->json = $decoded;
            }
        }
        return $this->json ?? [];
    }

    /**
     * Input: JSON body for JSON requests, form fields otherwise.
     * @return array<string,mixed>
     */
    public function input(): array
    {
        if (str_contains($this->header('content-type'), 'application/json')) {
            return $this->json();
        }
        return $this->post;
    }

    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        // Hostinger/LiteSpeed and many proxies set this header.
        return strtolower($this->header('x-forwarded-proto')) === 'https';
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function routeInt(string $key): int
    {
        $params = $this->attributes['route'] ?? [];
        $v = is_array($params) ? ($params[$key] ?? null) : null;
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 18) {
            throw HttpException::notFound();
        }
        return (int) $v;
    }
}
