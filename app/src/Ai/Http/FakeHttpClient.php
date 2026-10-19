<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Http;

/** Test double: returns queued responses (or throws queued transport errors) and records requests. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse|HttpTransportException> */
    private array $queue = [];
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string|array<string,mixed>}> */
    public array $requests = [];

    public function queue(HttpResponse|HttpTransportException $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    /** @param array<string,mixed> $data */
    public function queueJson(array $data, int $status = 200, array $headers = []): self
    {
        return $this->queue(new HttpResponse($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE), array_change_key_case($headers)));
    }

    public function send(string $method, string $url, array $headers, string|array $body, int $timeoutSeconds, int $connectTimeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpClient: no queued response for ' . $url);
        }
        if ($next instanceof HttpTransportException) {
            throw $next;
        }
        return $next;
    }
}
