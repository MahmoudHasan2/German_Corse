<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Config;
use GermanCoach\Core\EnvWriter;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;

/**
 * Portable desktop copy only (LOCAL_MODE=true, requests from this computer): lets the learner
 * paste their own free Groq / Gemini keys on the login page. Keys are written to .env and
 * never sent back to the browser. Groq is preferred for evaluation (Gemini when only it is set);
 * speech-to-text always needs Groq.
 */
final class LocalKeysController
{
    private const FORMATS = [
        'groq' => ['GROQ_API_KEY', '/^gsk_[A-Za-z0-9]{20,200}$/'],
        'gemini' => ['GEMINI_API_KEY', '/^AIza[A-Za-z0-9_-]{20,200}$/'],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public static function isLocalRequest(Config $config, Request $request): bool
    {
        return $config->bool('LOCAL_MODE') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    /** GET /api/local/keys */
    public function status(Request $request): Response
    {
        if (!self::isLocalRequest($this->config, $request)) {
            return Response::json(['ok' => true, 'enabled' => false]);
        }
        return Response::json([
            'ok' => true,
            'enabled' => true,
            'groq' => $this->config->string('GROQ_API_KEY') !== '',
            'gemini' => $this->config->string('GEMINI_API_KEY') !== '',
            'tutor_provider' => $this->config->string('TUTOR_PROVIDER'),
        ]);
    }

    /** POST /api/local/keys {groq_api_key?, gemini_api_key?} — an empty field keeps the saved key. */
    public function save(Request $request): Response
    {
        if (!self::isLocalRequest($this->config, $request)) {
            throw HttpException::forbidden('Keys can only be changed on this computer.');
        }
        $input = $request->input();
        $v = new Validator($input);
        $updates = [];
        foreach (self::FORMATS as $name => [$envKey, $pattern]) {
            $value = trim(is_string($input[$name . '_api_key'] ?? null) ? $input[$name . '_api_key'] : '');
            if ($value === '') {
                continue;
            }
            if (!preg_match($pattern, $value)) {
                $v->addError($name . '_api_key', 'invalid_key');
                continue;
            }
            $updates[$envKey] = $value;
        }
        $v->validate();
        if ($updates === []) {
            throw HttpException::validation(['groq_api_key' => 'required']);
        }

        $hasGroq = ($updates['GROQ_API_KEY'] ?? $this->config->string('GROQ_API_KEY')) !== '';
        $updates += [
            'AI_PROVIDER' => $hasGroq ? 'groq' : 'gemini',
            'TUTOR_PROVIDER' => $hasGroq ? 'groq' : 'gemini',
            'STT_PROVIDER' => 'groq',
            'TTS_PROVIDER' => 'browser',
        ];
        EnvWriter::set($this->envFile(), $updates);
        $this->logger->info('local_keys_saved', ['groq' => isset($updates['GROQ_API_KEY']), 'gemini' => isset($updates['GEMINI_API_KEY'])]);

        return Response::json(['ok' => true, 'groq' => $hasGroq, 'tutor_provider' => $updates['TUTOR_PROVIDER']]);
    }

    private function envFile(): string
    {
        $file = $this->config->string('LOCAL_ENV_FILE');
        return $file !== '' ? $file : $this->config->path('.env');
    }
}
