<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Http;

interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @param string|array<string,string|\CURLFile> $body string (JSON) or multipart fields
     * @throws HttpTransportException on network/timeout errors
     */
    public function send(string $method, string $url, array $headers, string|array $body, int $timeoutSeconds, int $connectTimeoutSeconds): HttpResponse;
}
