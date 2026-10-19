<?php

declare(strict_types=1);

namespace GermanCoach;

use GermanCoach\Core\Router;
use GermanCoach\Http\Controllers\AudioController;
use GermanCoach\Http\Controllers\AuthController;
use GermanCoach\Http\Controllers\HealthController;
use GermanCoach\Http\Controllers\LessonController;
use GermanCoach\Http\Controllers\LocalKeysController;
use GermanCoach\Http\Controllers\ProgressController;
use GermanCoach\Http\Controllers\ReviewController;
use GermanCoach\Http\Controllers\SessionController;
use GermanCoach\Http\Controllers\SettingsController;
use GermanCoach\Http\Controllers\SetupController;
use GermanCoach\Http\Controllers\ShellController;
use GermanCoach\Http\Controllers\VocabularyController;

/**
 * All HTTP routes. Every route requires authentication, and CSRF for non-GET methods,
 * unless stated otherwise. Request/response formats: docs/API.md.
 */
final class Routes
{
    public static function register(Router $r): void
    {
        $public = ['auth' => false];

        $r->get('/', [ShellController::class, 'index'], $public + ['session' => false]);
        $r->get('/api/health', [HealthController::class, 'show'], $public + ['session' => false]);

        // Setup & authentication
        $r->get('/api/setup/status', [SetupController::class, 'status'], $public);
        $r->post('/api/setup/run', [SetupController::class, 'run'], $public);
        $r->get('/api/auth/session', [AuthController::class, 'session'], $public);
        $r->post('/api/auth/login', [AuthController::class, 'login'], $public);
        $r->post('/api/auth/register', [AuthController::class, 'register'], $public);
        $r->post('/api/auth/logout', [AuthController::class, 'logout']);
        // Portable desktop copy only (LOCAL_MODE): the learner's own AI keys
        $r->get('/api/local/keys', [LocalKeysController::class, 'status'], $public);
        $r->post('/api/local/keys', [LocalKeysController::class, 'save'], $public);

        // Curriculum
        $r->get('/api/lessons', [LessonController::class, 'index']);
        $r->get('/api/lessons/{id}', [LessonController::class, 'show']);
        $r->post('/api/lessons/start', [LessonController::class, 'start']);

        // Tutoring sessions
        $r->get('/api/sessions/active', [SessionController::class, 'active']);
        $r->get('/api/sessions/{id}', [SessionController::class, 'show']);
        $r->post('/api/sessions/{id}/answer', [SessionController::class, 'answer']);
        $r->post('/api/sessions/{id}/hint', [SessionController::class, 'hint']);
        $r->post('/api/sessions/{id}/retry', [SessionController::class, 'retry']);
        $r->post('/api/sessions/{id}/skip', [SessionController::class, 'skip']);
        $r->post('/api/sessions/{id}/complete', [SessionController::class, 'complete']);

        // Voice
        $r->post('/api/sessions/{id}/audio', [AudioController::class, 'transcribe']);
        $r->get('/api/turns/{id}/audio', [AudioController::class, 'turn']);
        $r->get('/api/turns/{id}/speech-text', [AudioController::class, 'turnText']);
        $r->get('/api/lessons/{id}/dialogue/{line}/audio', [AudioController::class, 'dialogue']);
        $r->get('/api/vocabulary/{id}/audio', [AudioController::class, 'vocabulary']);

        // Dashboard, progress, reviews, vocabulary, assessments
        $r->get('/api/dashboard', [ProgressController::class, 'dashboard']);
        $r->get('/api/progress', [ProgressController::class, 'show']);
        $r->get('/api/reviews', [ReviewController::class, 'index']);
        $r->post('/api/reviews/start', [ReviewController::class, 'start']);
        $r->post('/api/reviews/{id}/complete', [ReviewController::class, 'complete']);
        $r->get('/api/vocabulary', [VocabularyController::class, 'index']);
        $r->post('/api/vocabulary/{id}/review', [VocabularyController::class, 'review']);
        $r->get('/api/assessments', [ReviewController::class, 'assessments']);
        $r->post('/api/assessments/start', [ReviewController::class, 'startAssessment']);

        // Settings, usage, account
        $r->get('/api/settings/usage', [SettingsController::class, 'usage']);
        $r->post('/api/settings/profile', [SettingsController::class, 'profile']);
        $r->post('/api/settings/password', [SettingsController::class, 'password']);
        $r->get('/api/account/export', [SettingsController::class, 'export']);
        $r->post('/api/account/delete', [SettingsController::class, 'delete']);
        $r->post('/api/admin/ai-switch', [SettingsController::class, 'aiSwitch']);
    }
}
