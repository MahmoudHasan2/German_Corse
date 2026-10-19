<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

use GermanCoach\Ai\Fake\FakeSpeech;
use GermanCoach\Ai\Fake\FakeTranscriber;
use GermanCoach\Ai\Fake\FakeTutor;
use GermanCoach\Ai\Gemini\GeminiTutor;
use GermanCoach\Ai\Groq\GroqTranscriber;
use GermanCoach\Ai\Groq\GroqTutor;
use GermanCoach\Ai\Http\CurlHttpClient;
use GermanCoach\Ai\Http\HttpClient;
use GermanCoach\Ai\OpenAi\OpenAiClient;
use GermanCoach\Ai\OpenAi\OpenAiSpeech;
use GermanCoach\Ai\OpenAi\OpenAiTranscriber;
use GermanCoach\Ai\OpenAi\OpenAiTutor;
use GermanCoach\Core\Config;

/**
 * Builds provider implementations from server-side configuration only.
 * Each service has its own provider (TUTOR_PROVIDER, STT_PROVIDER, TTS_PROVIDER; falling
 * back to AI_PROVIDER), so providers can be mixed, e.g. Gemini for evaluation, Groq for
 * transcription and the browser's own voice for speech. Model IDs live in .env.
 */
final class ProviderFactory
{
    public const TUTOR_PROVIDERS = ['openai', 'gemini', 'groq', 'fake'];
    public const STT_PROVIDERS = ['openai', 'groq', 'fake'];
    public const TTS_PROVIDERS = ['openai', 'browser', 'fake'];

    /** Defaults used when TUTOR_MODEL / STT_MODEL are empty (verified against provider docs 2026-10-18). */
    public const DEFAULT_MODELS = [
        'tutor' => ['openai' => 'gpt-5-mini', 'gemini' => 'gemini-3.5-flash-lite', 'groq' => 'openai/gpt-oss-120b', 'fake' => 'fake-tutor'],
        'stt' => ['openai' => 'gpt-4o-mini-transcribe', 'groq' => 'whisper-large-v3', 'fake' => 'fake-stt'],
    ];

    private const KEYS = ['openai' => 'OPENAI_API_KEY', 'gemini' => 'GEMINI_API_KEY', 'groq' => 'GROQ_API_KEY'];

    public function __construct(
        private readonly Config $config,
        private readonly ?HttpClient $http = null,
    ) {
    }

    /** @param string $service tutor | stt | tts (text is an alias of tutor) */
    public function provider(string $service = 'tutor'): string
    {
        $service = $service === 'text' ? 'tutor' : $service;
        $explicit = $this->config->string(strtoupper($service) . '_PROVIDER');
        if ($explicit !== '') {
            return $explicit;
        }
        $fallback = $this->config->string('AI_PROVIDER', 'openai');
        // Providers without a speech service fall back to the free browser voice.
        if ($service === 'tts' && !in_array($fallback, self::TTS_PROVIDERS, true)) {
            return 'browser';
        }
        return $fallback;
    }

    public function isConfigured(string $service = 'tutor'): bool
    {
        $service = $service === 'text' ? 'tutor' : $service;
        $provider = $this->provider($service);
        $allowed = match ($service) {
            'stt' => self::STT_PROVIDERS,
            'tts' => self::TTS_PROVIDERS,
            default => self::TUTOR_PROVIDERS,
        };
        if (!in_array($provider, $allowed, true)) {
            return false;
        }
        return isset(self::KEYS[$provider]) ? $this->config->string(self::KEYS[$provider]) !== '' : true;
    }

    public function tutor(): TutorEvaluator
    {
        return match ($this->provider('tutor')) {
            'fake' => new FakeTutor(),
            'openai' => new OpenAiTutor($this->client('openai'), $this->config->string('TUTOR_REASONING_EFFORT', 'low')),
            'groq' => new GroqTutor($this->client('groq'), $this->config->string('TUTOR_REASONING_EFFORT', 'low')),
            'gemini' => new GeminiTutor($this->client('gemini')),
            default => throw new ProviderException(ProviderException::NOT_CONFIGURED, null, false, 'unknown TUTOR_PROVIDER'),
        };
    }

    public function transcriber(): SpeechToText
    {
        return match ($this->provider('stt')) {
            'fake' => new FakeTranscriber(),
            'openai' => new OpenAiTranscriber(
                $this->client('openai'),
                $this->sttModel(),
                $this->config->string('STT_LANGUAGE', 'de'),
                $this->config->string('STT_PROMPT'),
                $this->config->bool('STT_LOGPROBS', true),
            ),
            'groq' => new GroqTranscriber($this->client('groq'), $this->sttModel(), $this->config->string('STT_LANGUAGE', 'de'), $this->config->string('STT_PROMPT')),
            default => throw new ProviderException(ProviderException::NOT_CONFIGURED, null, false, 'unknown STT_PROVIDER'),
        };
    }

    /** Server-side speech. Not available when TTS_PROVIDER=browser (the browser speaks itself). */
    public function speech(): TextToSpeech
    {
        return match ($this->provider('tts')) {
            'fake' => new FakeSpeech(),
            'openai' => new OpenAiSpeech(
                $this->client('openai'),
                $this->config->string('TTS_MODEL', 'gpt-4o-mini-tts'),
                $this->config->string('TTS_VOICE', 'marin'),
                $this->config->string('TTS_FORMAT', 'mp3'),
                $this->config->float('TTS_SPEED', 1.0),
                $this->config->string('TTS_INSTRUCTIONS'),
            ),
            default => throw new ProviderException(ProviderException::NOT_CONFIGURED, null, false, 'no server-side TTS for this provider'),
        };
    }

    public function sttModel(): string
    {
        $model = $this->config->string('STT_MODEL');
        return $model !== '' ? $model : (self::DEFAULT_MODELS['stt'][$this->provider('stt')] ?? 'unknown');
    }

    public function baseTutorModel(): string
    {
        $model = $this->config->string('TUTOR_MODEL');
        return $model !== '' ? $model : (self::DEFAULT_MODELS['tutor'][$this->provider('tutor')] ?? 'unknown');
    }

    /** Chooses the tutor model by task complexity (longer / writing answers → complex model). */
    public function tutorModel(string $skillArea, string $answer): string
    {
        $base = $this->baseTutorModel();
        if ($this->provider('tutor') === 'fake') {
            return $base;
        }
        $complex = $skillArea === 'writing' || mb_strlen($answer) >= $this->config->int('TUTOR_COMPLEX_MIN_CHARS', 220);
        return $complex ? $this->config->string('TUTOR_MODEL_COMPLEX', $base) : $base;
    }

    private function client(string $provider): OpenAiClient
    {
        [$base, $authHeader] = match ($provider) {
            'groq' => [$this->config->string('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'), 'Authorization'],
            'gemini' => [$this->config->string('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), 'x-goog-api-key'],
            default => [$this->config->string('OPENAI_BASE_URL', 'https://api.openai.com/v1'), 'Authorization'],
        };
        return new OpenAiClient(
            $this->http ?? new CurlHttpClient(),
            $this->config->string(self::KEYS[$provider] ?? 'OPENAI_API_KEY'),
            $base,
            $this->config->int('AI_TIMEOUT_SECONDS', 40),
            $this->config->int('AI_CONNECT_TIMEOUT_SECONDS', 10),
            max(0, min(3, $this->config->int('AI_MAX_RETRIES', 1))),
            $this->config->int('AI_RETRY_BASE_DELAY_MS', 600),
            $provider === 'openai' ? $this->config->string('OPENAI_ORGANIZATION') : '',
            $provider === 'openai' ? $this->config->string('OPENAI_PROJECT') : '',
            null,
            $authHeader,
            max(1, min(60, $this->config->int('AI_MAX_RETRY_WAIT_SECONDS', 15))),
        );
    }
}
