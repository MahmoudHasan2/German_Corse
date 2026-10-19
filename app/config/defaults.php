<?php

declare(strict_types=1);

/*
 * Default configuration. Every key can be overridden in .env or by a real
 * environment variable. Secrets have no defaults. See .env.example and
 * docs/CONFIG_CHECKLIST.md for explanations.
 */
return [
    // Application
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => '',
    'APP_TIMEZONE' => 'Europe/Berlin',
    'LOG_LEVEL' => 'info',
    'SETUP_TOKEN' => '',
    'ALLOW_REGISTRATION' => 'false',
    // Portable desktop copy: no setup token, AI keys entered on the login page (127.0.0.1 only)
    'LOCAL_MODE' => 'false',
    'LOCAL_ENV_FILE' => '',

    // Session
    'SESSION_NAME' => 'gac_session',
    'SESSION_LIFETIME_MINUTES' => '720',
    'SESSION_SECURE_COOKIE' => 'auto',

    // Database
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASSWORD' => '',
    'DB_SOCKET' => '',

    // AI provider
    'AI_ENABLED' => 'true',
    'AI_PROVIDER' => 'openai',          // fallback for the three settings below
    'TUTOR_PROVIDER' => '',             // openai | gemini | groq | fake
    'STT_PROVIDER' => '',               // openai | groq | fake
    'TTS_PROVIDER' => '',               // openai | browser | fake
    'OPENAI_API_KEY' => '',
    'GEMINI_API_KEY' => '',
    'GEMINI_BASE_URL' => 'https://generativelanguage.googleapis.com/v1beta',
    'GROQ_API_KEY' => '',
    'GROQ_BASE_URL' => 'https://api.groq.com/openai/v1',
    'OPENAI_BASE_URL' => 'https://api.openai.com/v1',
    'OPENAI_ORGANIZATION' => '',
    'OPENAI_PROJECT' => '',
    // Empty model = the provider's default (see ProviderFactory::DEFAULT_MODELS).
    'TUTOR_MODEL' => '',
    'TUTOR_MODEL_COMPLEX' => '',
    'TUTOR_COMPLEX_MIN_CHARS' => '220',
    'TUTOR_REASONING_EFFORT' => 'low',
    'TUTOR_MAX_OUTPUT_TOKENS' => '2000',
    'STT_MODEL' => '',
    'STT_LANGUAGE' => 'de',
    'STT_LOGPROBS' => 'true',
    'STT_PROMPT' => 'Antwort eines Deutschlernenden. Transkribiere genau, was gesagt wurde, und korrigiere keine Fehler.',
    'STT_LOW_CONFIDENCE_THRESHOLD' => '0.55',
    'TTS_MODEL' => 'gpt-4o-mini-tts',
    'TTS_VOICE' => 'marin',
    'TTS_FORMAT' => 'mp3',
    'TTS_SPEED' => '0.95',
    'TTS_INSTRUCTIONS' => 'Sprich klares, natürliches Hochdeutsch, ruhig und deutlich für Deutschlernende.',
    'AI_TIMEOUT_SECONDS' => '40',
    'AI_CONNECT_TIMEOUT_SECONDS' => '10',
    'AI_MAX_RETRIES' => '1',
    'AI_MAX_RETRY_WAIT_SECONDS' => '15',
    'AI_RETRY_BASE_DELAY_MS' => '600',
    'PRICING_FILE' => 'config/pricing.json',

    // Audio
    'AUDIO_MAX_UPLOAD_BYTES' => '5242880',
    'AUDIO_MAX_SECONDS' => '60',

    // Usage limits and budget (USD)
    'LIMIT_DAILY_REQUESTS' => '250',
    'LIMIT_DAILY_AUDIO_MINUTES' => '30',
    'LIMIT_USER_DAILY_BUDGET_USD' => '0.50',
    'LIMIT_SESSION_REQUESTS' => '100',
    'LIMIT_MONTHLY_BUDGET_USD' => '10',
    'LIMIT_EMERGENCY_THRESHOLD' => '0.95',
    'LIMIT_WARNING_RATIO' => '0.8',
    'RATE_AI_PER_MINUTE' => '15',
    'RATE_LOGIN_PER_15MIN' => '5',
    'RATE_LOGIN_IP_PER_15MIN' => '20',

    // Mastery engine
    'MASTERY_REQUIRE_TRANSFER' => 'true',
    'MASTERY_STRATEGY_CHANGE_AFTER' => '2',
    'MASTERY_MAX_ATTEMPTS' => '5',
    'MASTERY_MIN_CONFIDENCE' => '0.6',
    'MASTERY_MINOR_ERRORS_PASS' => 'true',
    'MASTERY_COPY_SIMILARITY' => '0.9',
    'LESSON_MIN_MASTERED_RATIO' => '0.7',
];
