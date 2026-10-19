<?php

declare(strict_types=1);

namespace GermanCoach\Ai\OpenAi;

use GermanCoach\Ai\Http\HttpClient;
use GermanCoach\Ai\Http\HttpResponse;
use GermanCoach\Ai\Http\HttpTransportException;
use GermanCoach\Ai\ProviderException;

/**
 * Shared transport for OpenAI-style JSON APIs (OpenAI, Groq, Gemini): auth headers, timeouts, bounded retries
 * with exponential backoff (honouring Retry-After), and error normalization.
 * The API key never leaves this class except in the Authorization header.
 */
final class OpenAiClient
{
    /** @var \Closure(int):void */
    private \Closure $sleeper;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $timeoutSeconds = 40,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $maxRetries = 1,
        private readonly int $retryBaseDelayMs = 600,
        private readonly string $organization = '',
        private readonly string $project = '',
        ?\Closure $sleeper = null,
        /** Header carrying the key: "Authorization" (Bearer — OpenAI, Groq) or e.g. "x-goog-api-key" (Gemini). */
        private readonly string $authHeader = 'Authorization',
        /** Longest wait honoured from a Retry-After hint (free tiers ask for ~10 s). */
        private readonly int $maxRetryWaitSeconds = 15,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /** @param array<string,mixed> $payload */
    public function postJson(string $path, array $payload): HttpResponse
    {
        return $this->send($path, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,string|\CURLFile> $fields */
    public function postMultipart(string $path, array $fields): HttpResponse
    {
        return $this->send($path, [], $fields);
    }

    /** @param array<string,string> $headers @param string|array<string,string|\CURLFile> $body */
    private function send(string $path, array $headers, string|array $body): HttpResponse
    {
        if ($this->apiKey === '') {
            throw new ProviderException(ProviderException::NOT_CONFIGURED, null, false, 'OPENAI_API_KEY is empty');
        }
        if (strcasecmp($this->authHeader, 'Authorization') === 0) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        } else {
            $headers[$this->authHeader] = $this->apiKey;
        }
        if ($this->organization !== '') {
            $headers['OpenAI-Organization'] = $this->organization;
        }
        if ($this->project !== '') {
            $headers['OpenAI-Project'] = $this->project;
        }
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->send('POST', $url, $headers, $body, $this->timeoutSeconds, $this->connectTimeoutSeconds);
                if ($response->status >= 200 && $response->status < 300) {
                    return $response;
                }
                $retryAfter = self::retryAfter($response->header('retry-after'));
                $error = ProviderException::fromHttp($response->status, $response->json(), $response->header('x-request-id'), $retryAfter);
            } catch (HttpTransportException $e) {
                $error = ProviderException::fromCurl($e->errno, $e->getMessage());
            }
            if (!$error->retryable || $attempt >= $this->maxRetries) {
                throw $error;
            }
            $delay = $this->retryBaseDelayMs * (2 ** $attempt);
            if ($error->retryAfterSeconds !== null) {
                $delay = max($delay, min($this->maxRetryWaitSeconds, $error->retryAfterSeconds) * 1000);
            }
            ($this->sleeper)($delay);
            $attempt++;
        }
    }

    private static function retryAfter(?string $value): ?int
    {
        if ($value === null || !ctype_digit(trim($value))) {
            return null;
        }
        return (int) trim($value);
    }
}
