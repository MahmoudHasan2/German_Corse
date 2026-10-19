<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Auth\AccountService;
use GermanCoach\Auth\AuthService;
use GermanCoach\Auth\UserRepository;
use GermanCoach\Core\Config;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;
use GermanCoach\Usage\AppSettings;
use GermanCoach\Usage\PricingTable;
use GermanCoach\Usage\UsageGuard;
use GermanCoach\Usage\UsageRepository;

final class SettingsController
{
    public function __construct(
        private readonly UsageGuard $guard,
        private readonly UsageRepository $usage,
        private readonly PricingTable $pricing,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly AccountService $account,
        private readonly AppSettings $settings,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly \GermanCoach\Ai\ProviderFactory $providers,
    ) {
    }

    /** GET /api/settings/usage */
    public function usage(Request $request): Response
    {
        $user = $request->attribute('user');
        $snapshot = $this->guard->snapshot((int) $user['id']);
        return Response::json([
            'ok' => true,
            'usage' => $snapshot,
            'history' => $this->usage->dailyHistory((int) $user['id'], 14),
            'pricing_as_of' => $this->pricing->asOf(),
            'models' => [
                'tutor' => $this->providers->provider('tutor') . ' · ' . $this->providers->baseTutorModel(),
                'tutor_complex' => $this->config->string('TUTOR_MODEL_COMPLEX', $this->providers->baseTutorModel()),
                'transcription' => $this->providers->provider('stt') . ' · ' . $this->providers->sttModel(),
                'speech' => $this->providers->provider('tts') === 'browser' ? 'browser voice' : $this->providers->provider('tts') . ' · ' . $this->config->string('TTS_MODEL'),
            ],
            'is_admin' => $user['role'] === 'admin',
            'emergency_shutdown_at' => $this->settings->get(UsageGuard::EMERGENCY_MARKER),
            'note_ar' => 'التكاليف المعروضة تقديرية وتُحسب على الخادم. اضبط أيضًا حدًّا للميزانية في لوحة OpenAI، فهو الحماية النهائية من أي استهلاك غير متوقّع.',
        ]);
    }

    /** POST /api/settings/profile {display_name, daily_goal_minutes, target_level} */
    public function profile(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $v = new Validator($request->input());
        $fields = array_filter([
            'display_name' => $v->string('display_name', 1, 80, false),
            'daily_goal_minutes' => $v->int('daily_goal_minutes', 10, 300, false),
            'target_level' => $v->enum('target_level', ['A1', 'A2', 'B1'], '') ?: null,
        ], static fn ($x) => $x !== null);
        $v->validate();
        $this->users->updateProfile($userId, $fields);
        return Response::json(['ok' => true, 'user' => UserRepository::publicView((array) $this->users->find($userId))]);
    }

    /** POST /api/settings/password {current_password, new_password} */
    public function password(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $v = new Validator($request->input());
        $current = $v->string('current_password', 1, 200);
        $new = $v->string('new_password', 1, 200);
        if ($new !== null && ($err = AuthService::validatePassword($new)) !== null) {
            $v->addError('new_password', $err);
        }
        $v->validate();
        if (!$this->auth->verifyPassword($userId, (string) $current)) {
            throw HttpException::validation(['current_password' => 'incorrect']);
        }
        $this->users->updatePasswordHash($userId, password_hash((string) $new, PASSWORD_DEFAULT));
        $csrf = $this->auth->login((array) $this->users->find($userId)); // new session id after credential change
        return Response::json(['ok' => true, 'csrf_token' => $csrf]);
    }

    /** GET /api/account/export — all of the learner's data as a JSON download. */
    public function export(Request $request): Response
    {
        $data = $this->account->export((int) $request->attribute('user')['id']);
        return Response::json($data)
            ->withHeader('Content-Disposition', 'attachment; filename="german-coach-export-' . gmdate('Y-m-d') . '.json"');
    }

    /** POST /api/account/delete {password, confirm: "DELETE"} */
    public function delete(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $v = new Validator($request->input());
        $password = $v->string('password', 1, 200);
        $confirm = $v->string('confirm', 1, 20);
        $v->validate();
        if ($confirm !== 'DELETE') {
            throw HttpException::validation(['confirm' => 'type_DELETE']);
        }
        if (!$this->auth->verifyPassword($userId, (string) $password)) {
            throw HttpException::validation(['password' => 'incorrect']);
        }
        $this->account->delete($userId);
        $this->logger->info('account_deleted', ['user_id' => $userId]);
        $csrf = $this->auth->logout();
        return Response::json(['ok' => true, 'csrf_token' => $csrf]);
    }

    /** POST /api/admin/ai-switch {enabled: bool} — admin-only kill switch for all AI requests. */
    public function aiSwitch(Request $request): Response
    {
        $user = $request->attribute('user');
        if ($user['role'] !== 'admin') {
            throw HttpException::forbidden('Only the administrator can change this.');
        }
        $v = new Validator($request->input());
        $enabled = $v->bool('enabled', true);
        $v->validate();
        if ($enabled) {
            $this->settings->delete(UsageGuard::KILL_SWITCH);
            $this->settings->delete(UsageGuard::EMERGENCY_MARKER);
        } else {
            $this->settings->set(UsageGuard::KILL_SWITCH, '1');
        }
        $this->logger->warning('ai_switch_changed', ['enabled' => $enabled, 'by' => (int) $user['id']]);
        return Response::json(['ok' => true, 'ai_enabled' => $enabled]);
    }
}
