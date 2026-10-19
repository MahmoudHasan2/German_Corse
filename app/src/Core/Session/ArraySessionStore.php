<?php

declare(strict_types=1);

namespace GermanCoach\Core\Session;

/**
 * In-memory session store used by tests. Several "browsers" can share one
 * backing map keyed by session id, which lets tests simulate cookies,
 * logout/login and session-fixation behaviour.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string,array<string,mixed>> */
    private static array $sessions = [];
    private string $id;

    public function __construct(?string $id = null)
    {
        $this->id = ($id !== null && isset(self::$sessions[$id])) ? $id : bin2hex(random_bytes(16));
    }

    public static function reset(): void
    {
        self::$sessions = [];
    }

    public function start(): void
    {
        self::$sessions[$this->id] ??= [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return self::$sessions[$this->id][$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        self::$sessions[$this->id][$key] = $value;
    }

    public function remove(string $key): void
    {
        unset(self::$sessions[$this->id][$key]);
    }

    public function regenerate(): void
    {
        $data = self::$sessions[$this->id] ?? [];
        unset(self::$sessions[$this->id]);
        $this->id = bin2hex(random_bytes(16));
        self::$sessions[$this->id] = $data;
    }

    public function destroy(): void
    {
        unset(self::$sessions[$this->id]);
        $this->id = bin2hex(random_bytes(16));
        self::$sessions[$this->id] = [];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cookieHeader(): ?string
    {
        return 'gac_session=' . $this->id . '; Path=/; HttpOnly; SameSite=Lax';
    }
}
