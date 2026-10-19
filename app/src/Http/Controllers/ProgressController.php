<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Progress\ProgressService;

final class ProgressController
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /** GET /api/dashboard */
    public function dashboard(Request $request): Response
    {
        return Response::json(['ok' => true] + $this->progress->dashboard($request->attribute('user')));
    }

    /** GET /api/progress */
    public function show(Request $request): Response
    {
        return Response::json(['ok' => true] + $this->progress->progress($request->attribute('user')));
    }
}
