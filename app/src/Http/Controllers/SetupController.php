<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Auth\AuthService;
use GermanCoach\Auth\UserRepository;
use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Migrator;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Session\SessionStore;
use GermanCoach\Core\Validator;
use GermanCoach\Curriculum\CurriculumImporter;

/**
 * Web installer for hosts without SSH. Protected by SETUP_TOKEN from .env.
 * - Runs pending migrations and imports the curriculum (safe to repeat for upgrades).
 * - Creates the first (admin) account only while no account exists.
 */
final class SetupController
{
    private const MAX_FAILED_TOKENS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly SessionStore $session,
        private readonly Logger $logger,
        private readonly AuthService $auth,
    ) {
    }

    /** GET /api/setup/status */
    public function status(Request $request): Response
    {
        $dbOk = true;
        $pending = [];
        $users = 0;
        try {
            $migrator = new Migrator($this->db, $this->config->path('database/migrations'));
            $pending = $migrator->pending();
            $users = $pending === [] ? (new UserRepository($this->db))->count() : 0;
        } catch (\Throwable) {
            $dbOk = false;
        }
        return Response::json([
            'ok' => true,
            'setup_enabled' => $this->tokenConfigured() || LocalKeysController::isLocalRequest($this->config, $request),
            'token_required' => !LocalKeysController::isLocalRequest($this->config, $request),
            'database_reachable' => $dbOk,
            'migrations_pending' => count($pending),
            'account_exists' => $users > 0,
        ]);
    }

    /** POST /api/setup/run {token, email?, password?, display_name?} */
    public function run(Request $request): Response
    {
        if (LocalKeysController::isLocalRequest($this->config, $request)) {
            return $this->install($request);
        }
        if (!$this->tokenConfigured()) {
            throw HttpException::forbidden('Setup is disabled. Set a SETUP_TOKEN (24+ characters) in .env.');
        }
        $failures = (int) $this->session->get('setup_failures', 0);
        if ($failures >= self::MAX_FAILED_TOKENS) {
            throw new HttpException(429, 'too_many_attempts', 'Too many wrong setup tokens. Restart your browser and try later.');
        }
        $input = $request->input();
        $token = is_string($input['token'] ?? null) ? $input['token'] : '';
        if (!hash_equals($this->config->string('SETUP_TOKEN'), $token)) {
            $this->session->set('setup_failures', $failures + 1);
            $this->logger->warning('setup_token_rejected');
            usleep(300_000);
            throw new HttpException(403, 'invalid_setup_token', 'The setup token is not correct.');
        }
        return $this->install($request);
    }

    private function install(Request $request): Response
    {
        $input = $request->input();
        $migrator = new Migrator($this->db, $this->config->path('database/migrations'));
        $applied = $migrator->migrate();
        $imported = (new CurriculumImporter($this->db))->importDirectory($this->config->path('curriculum'));

        $users = new UserRepository($this->db);
        $created = false;
        $wantsAccount = isset($input['email']) && $input['email'] !== '';
        if ($wantsAccount) {
            if ($users->count() > 0) {
                throw HttpException::conflict('account_exists', 'An account already exists. Log in instead.');
            }
            [$email, $password, $name] = AuthController::validateNewAccount($request);
            $id = $users->create($email, $password, $name, 'admin');
            $this->auth->login((array) $users->find($id));
            $created = true;
        }
        $this->logger->info('setup_run', ['migrations' => $applied, 'curriculum' => $imported, 'account_created' => $created]);

        return Response::json([
            'ok' => true,
            'migrations_applied' => $applied,
            'curriculum' => $imported,
            'account_created' => $created,
            'csrf_token' => $created ? (new \GermanCoach\Core\Csrf($this->session))->token() : null,
        ]);
    }

    private function tokenConfigured(): bool
    {
        return strlen($this->config->string('SETUP_TOKEN')) >= 24;
    }
}
