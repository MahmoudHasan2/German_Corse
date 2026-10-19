<?php

declare(strict_types=1);

namespace GermanCoach\Core\Session;

/** PHP native sessions with hardened cookie settings. */
final class NativeSessionStore implements SessionStore
{
    public function __construct(
        private readonly string $name,
        private readonly int $lifetimeSeconds,
        private readonly bool $secure,
        private readonly ?string $savePath = null,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) $this->lifetimeSeconds);
        if ($this->savePath !== null && is_dir($this->savePath) && is_writable($this->savePath)) {
            session_save_path($this->savePath);
        }
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Idle timeout enforced server-side.
        $now = time();
        $last = $_SESSION['_last_seen'] ?? null;
        if (is_int($last) && ($now - $last) > $this->lifetimeSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_seen'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public function id(): string
    {
        return (string) session_id();
    }

    public function cookieHeader(): ?string
    {
        return null;
    }
}
