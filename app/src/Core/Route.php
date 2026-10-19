<?php

declare(strict_types=1);

namespace GermanCoach\Core;

final class Route
{
    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param array{auth?:bool,csrf?:bool,name?:string} $options
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $regex,
        public readonly mixed $handler,
        public readonly array $options,
    ) {
    }

    /** Authentication is required unless explicitly disabled. */
    public function requiresAuth(): bool
    {
        return $this->options['auth'] ?? true;
    }

    /** CSRF is checked for every state-changing method unless explicitly disabled. */
    public function requiresCsrf(): bool
    {
        if (array_key_exists('csrf', $this->options)) {
            return (bool) $this->options['csrf'];
        }
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
