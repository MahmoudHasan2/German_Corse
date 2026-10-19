<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;
use GermanCoach\Learning\SessionRepository;
use GermanCoach\Learning\SessionService;
use GermanCoach\Learning\TurnRepository;

final class SessionController
{
    public function __construct(
        private readonly SessionService $service,
        private readonly SessionRepository $sessions,
        private readonly TurnRepository $turns,
        private readonly Database $db,
    ) {
    }

    /** GET /api/sessions/active — unfinished sessions to resume */
    public function active(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $rows = $this->db->all(
            "SELECT s.id, s.mode, s.state, s.lesson_id, s.last_activity_at, l.title_ar, l.title_de, l.level
             FROM learning_sessions s LEFT JOIN lessons l ON l.id = s.lesson_id
             WHERE s.user_id = ? AND s.state <> 'completed' ORDER BY s.last_activity_at DESC LIMIT 10",
            [$userId]
        );
        return Response::json(['ok' => true, 'sessions' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'mode' => (string) $r['mode'], 'state' => (string) $r['state'],
            'lesson_id' => $r['lesson_id'] !== null ? (int) $r['lesson_id'] : null,
            'title_ar' => $r['title_ar'], 'title_de' => $r['title_de'], 'level' => $r['level'],
            'last_activity_at' => (string) $r['last_activity_at'],
        ], $rows)]);
    }

    /** GET /api/sessions/{id} */
    public function show(Request $request): Response
    {
        return Response::json(['ok' => true, 'session' => $this->service->show($this->userId($request), $request->routeInt('id'))]);
    }

    /** POST /api/sessions/{id}/answer {answer_text, client_submission_id, input_mode?, transcript_id?} */
    public function answer(Request $request): Response
    {
        $v = new Validator($request->input());
        $text = $v->string('answer_text', 1, 2000);
        $submission = $v->submissionId();
        $mode = $v->enum('input_mode', ['text', 'audio'], 'text');
        $transcriptId = $v->int('transcript_id', 1, PHP_INT_MAX, $mode === 'audio');
        $v->validate();
        $result = $this->service->answer($this->userId($request), $request->routeInt('id'), [
            'answer_text' => (string) $text,
            'client_submission_id' => (string) $submission,
            'input_mode' => (string) $mode,
            'transcript_id' => $transcriptId,
        ]);
        return Response::json($result);
    }

    /** POST /api/sessions/{id}/hint */
    public function hint(Request $request): Response
    {
        return Response::json($this->service->hint($this->userId($request), $request->routeInt('id')));
    }

    /**
     * POST /api/sessions/{id}/retry — the tutor asks the current question again (e.g. after
     * reading feedback). Counters and progression are not changed.
     */
    public function retry(Request $request): Response
    {
        $userId = $this->userId($request);
        $sessionId = $request->routeInt('id');
        $s = $this->sessions->findForUser($sessionId, $userId) ?? throw HttpException::notFound('Session not found.');
        if (!in_array($s['state'], SessionRepository::ANSWERABLE, true)) {
            throw HttpException::conflict('not_answerable', 'There is no open exercise.');
        }
        $kind = $s['phase'] === 'transfer' ? 'transfer_question' : 'question';
        $last = $this->turns->latestOfKind($sessionId, $kind);
        $turn = $last !== null ? $this->turns->findForUser($last, $userId) : null;
        if ($turn !== null) {
            $this->turns->add($sessionId, $userId, $turn['exercise_id'] !== null ? (int) $turn['exercise_id'] : null, 'tutor', $kind, $turn['text_de'], $turn['text_ar'], json_decode((string) ($turn['payload'] ?? 'null'), true));
        }
        return Response::json(['ok' => true, 'session' => $this->service->show($userId, $sessionId)]);
    }

    /** POST /api/sessions/{id}/skip */
    public function skip(Request $request): Response
    {
        return Response::json($this->service->skip($this->userId($request), $request->routeInt('id')));
    }

    /** POST /api/sessions/{id}/complete */
    public function complete(Request $request): Response
    {
        return Response::json($this->service->complete($this->userId($request), $request->routeInt('id')));
    }

    private function userId(Request $request): int
    {
        return (int) $request->attribute('user')['id'];
    }
}
