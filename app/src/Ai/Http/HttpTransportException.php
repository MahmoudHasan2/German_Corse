<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Http;

final class HttpTransportException extends \RuntimeException
{
    public function __construct(public readonly int $errno, string $message)
    {
        parent::__construct($message, $errno);
    }
}
