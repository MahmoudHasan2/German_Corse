<?php

declare(strict_types=1);

namespace GermanCoach\Core;

use GermanCoach\Core\Session\SessionStore;

/** Synchronizer-token CSRF protection; the token travels in the X-CSRF-Token header. */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function rotate(): string
    {
        $this->session->remove(self::KEY);
        return $this->token();
    }

    public function validate(Request $request): bool
    {
        $expected = $this->session->get(self::KEY);
        $given = $request->header('x-csrf-token');
        if ($given === '') {
            $given = is_string($request->post['_csrf'] ?? null) ? $request->post['_csrf'] : '';
        }
        return is_string($expected) && strlen($expected) === 64 && $given !== '' && hash_equals($expected, $given);
    }
}
