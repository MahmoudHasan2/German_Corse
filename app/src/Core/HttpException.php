<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * An error that is safe to show to the client: status, stable machine code,
 * human message (Arabic-first UI translates codes; message is a fallback).
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        /** @var array<string,string> */
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthenticated', 'Please log in.');
    }

    public static function forbidden(string $message = 'Forbidden.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    /** @param array<string,string> $fieldErrors */
    public static function validation(array $fieldErrors): self
    {
        return new self(422, 'validation_failed', 'Some input is invalid.', ['fields' => $fieldErrors]);
    }
}
