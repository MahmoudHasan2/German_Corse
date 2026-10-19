<?php

declare(strict_types=1);

namespace GermanCoach\Auth;

use GermanCoach\Core\Config;
use GermanCoach\Core\Csrf;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Session\SessionStore;
use GermanCoach\Usage\RateLimiter;

final class AuthService
{
    private const SESSION_KEY = 'user_id';
    /** @var array<string,mixed>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionStore $session,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function currentUser(): ?array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $id = $this->session->get(self::SESSION_KEY);
        if (!is_int($id) || $id <= 0) {
            return null;
        }
        $user = $this->users->find($id);
        if ($user === null) {
            $this->session->remove(self::SESSION_KEY);
            return null;
        }
        return $this->cached = $user;
    }

    /**
     * Verifies credentials with per-account and per-IP rate limits.
     * Failure messages never reveal whether the e-mail exists.
     * @return array<string,mixed>
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $window = 15 * 60;
        $accountBucket = 'login_account:' . mb_strtolower($email);
        $ipBucket = 'login_ip:' . $ip;
        $accountLimit = max(1, $this->config->int('RATE_LOGIN_PER_15MIN', 5));
        $ipLimit = max(1, $this->config->int('RATE_LOGIN_IP_PER_15MIN', 20));

        $ipResult = $this->limiter->hit($ipBucket, $ipLimit, $window);
        $accountCheck = $this->limiter->check($accountBucket, $accountLimit - 1, $window);
        if (!$ipResult->allowed || !$accountCheck->allowed) {
            $this->logger->warning('login_rate_limited', ['ip_hash' => substr(hash('sha256', $ip), 0, 12)]);
            $retry = max($ipResult->retryAfterSeconds, $accountCheck->retryAfterSeconds, 60);
            throw new HttpException(429, 'too_many_attempts', 'Too many login attempts. Try again later.', ['retry_after' => $retry], ['Retry-After' => (string) $retry]);
        }

        $user = $this->users->findByEmail($email);
        // Verify against a dummy hash for unknown users to keep timing similar.
        $hash = $user['password_hash'] ?? '$2y$10$czCC9JSPUGf.0PfVnE2kdO056SwqPI2rcBXMiatGujdfWRPWpXYOS';
        if (!password_verify($password, (string) $hash) || $user === null) {
            $this->limiter->hit($accountBucket, $accountLimit, $window);
            throw new HttpException(401, 'invalid_credentials', 'E-mail or password is incorrect.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        $this->limiter->reset($accountBucket);
        return $user;
    }

    /** @param array<string,mixed> $user */
    public function login(array $user): string
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->users->touchLogin((int) $user['id']);
        $this->cached = $user;
        return (new Csrf($this->session))->rotate();
    }

    public function logout(): string
    {
        $this->cached = null;
        $this->session->destroy();
        $this->session->start();
        return (new Csrf($this->session))->rotate();
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $user = $this->users->find($userId);
        return $user !== null && password_verify($password, (string) $user['password_hash']);
    }

    public static function validatePassword(string $password): ?string
    {
        $len = mb_strlen($password);
        if ($len < 10) {
            return 'password_too_short';
        }
        if ($len > 200) {
            return 'password_too_long';
        }
        return null;
    }
}
