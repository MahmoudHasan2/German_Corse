<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Auth\AuthService;
use GermanCoach\Auth\UserRepository;
use GermanCoach\Core\Config;
use GermanCoach\Core\Csrf;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Session\SessionStore;
use GermanCoach\Core\Validator;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly SessionStore $session,
        private readonly Config $config,
        private readonly \GermanCoach\Ai\ProviderFactory $providers,
    ) {
    }

    /** GET /api/auth/session — bootstrap data for the SPA: auth state + CSRF token. */
    public function session(Request $request): Response
    {
        $setupRequired = false;
        try {
            $user = $this->auth->currentUser();
            $setupRequired = $user === null && $this->users->count() === 0;
        } catch (\PDOException) {
            $user = null;
            $setupRequired = true; // database not configured or not migrated yet
        }
        return Response::json([
            'ok' => true,
            'authenticated' => $user !== null,
            'user' => $user !== null ? UserRepository::publicView($user) : null,
            'csrf_token' => (new Csrf($this->session))->token(),
            'setup_required' => $setupRequired,
            'registration_open' => $this->config->bool('ALLOW_REGISTRATION'),
            // 'browser': the client speaks German with the device's own voice (free, no server audio).
            'tts_mode' => $this->providers->provider('tts') === 'browser' ? 'browser' : 'server',
        ]);
    }

    /** POST /api/auth/login {email, password} */
    public function login(Request $request): Response
    {
        $v = new Validator($request->input());
        $email = $v->email('email');
        $password = $v->string('password', 1, 200);
        $v->validate();

        $user = $this->auth->attempt((string) $email, (string) $password, $request->ip());
        $csrf = $this->auth->login($user);
        return Response::json(['ok' => true, 'user' => UserRepository::publicView($user), 'csrf_token' => $csrf]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): Response
    {
        $csrf = $this->auth->logout();
        return Response::json(['ok' => true, 'csrf_token' => $csrf]);
    }

    /** POST /api/auth/register — only when ALLOW_REGISTRATION=true (closed by default). */
    public function register(Request $request): Response
    {
        if (!$this->config->bool('ALLOW_REGISTRATION')) {
            throw HttpException::forbidden('Registration is closed.');
        }
        [$email, $password, $name] = self::validateNewAccount($request);
        if ($this->users->findByEmail($email) !== null) {
            throw HttpException::validation(['email' => 'already_registered']);
        }
        $id = $this->users->create($email, $password, $name);
        $user = (array) $this->users->find($id);
        $csrf = $this->auth->login($user);
        return Response::json(['ok' => true, 'user' => UserRepository::publicView($user), 'csrf_token' => $csrf], 201);
    }

    /** @return array{0:string,1:string,2:string} */
    public static function validateNewAccount(Request $request): array
    {
        $v = new Validator($request->input());
        $email = $v->email('email');
        $password = $v->string('password', 1, 200);
        $name = $v->string('display_name', 1, 80);
        if ($password !== null && ($err = AuthService::validatePassword($password)) !== null) {
            $v->addError('password', $err);
        }
        $v->validate();
        return [(string) $email, (string) $password, (string) $name];
    }
}
