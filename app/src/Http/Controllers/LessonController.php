<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;
use GermanCoach\Curriculum\LessonService;
use GermanCoach\Learning\SessionService;

final class LessonController
{
    public function __construct(
        private readonly LessonService $lessons,
        private readonly SessionService $sessions,
    ) {
    }

    /** GET /api/lessons */
    public function index(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::json(['ok' => true, 'lessons' => $this->lessons->lessonsForUser((int) $user['id'], $user['start_level'])]);
    }

    /** GET /api/lessons/{id} */
    public function show(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::json(['ok' => true] + $this->lessons->detail((int) $user['id'], $user['start_level'], $request->routeInt('id')));
    }

    /** POST /api/lessons/start {lesson_id} */
    public function start(Request $request): Response
    {
        $v = new Validator($request->input());
        $lessonId = $v->int('lesson_id', 1, PHP_INT_MAX);
        $v->validate();
        return Response::json(['ok' => true, 'session' => $this->sessions->startLesson($request->attribute('user'), (int) $lessonId)], 201);
    }
}
