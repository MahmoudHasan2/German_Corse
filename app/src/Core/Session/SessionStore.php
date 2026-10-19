<?php

declare(strict_types=1);

namespace GermanCoach\Core\Session;

interface SessionStore
{
    public function start(): void;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session id, keeping data (prevents session fixation). */
    public function regenerate(): void;

    /** Clears data and invalidates the id. */
    public function destroy(): void;

    public function id(): string;

    /** Set-Cookie value when the store manages cookies itself (tests); null for native PHP sessions. */
    public function cookieHeader(): ?string;
}
