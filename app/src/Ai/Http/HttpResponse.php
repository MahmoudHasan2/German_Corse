<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Http;

final class HttpResponse
{
    /** @param array<string,string> $headers lower-case names */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : null;
    }
}
