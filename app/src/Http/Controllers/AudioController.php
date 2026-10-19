<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderFactory;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Audio\AudioUploadValidator;
use GermanCoach\Audio\TempAudioStore;
use GermanCoach\Audio\TtsService;
use GermanCoach\Core\Config;
use GermanCoach\Core\Container;
use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Learning\SessionRepository;
use GermanCoach\Learning\TranscriptRepository;
use GermanCoach\Learning\TurnRepository;
use GermanCoach\Mastery\MasteryPolicy;
use GermanCoach\Usage\CostCalculator;
use GermanCoach\Usage\UsageGuard;
use GermanCoach\Usage\UsageRepository;

final class AudioController
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Database $db,
        private readonly SessionRepository $sessions,
        private readonly TranscriptRepository $transcripts,
        private readonly TurnRepository $turns,
        private readonly UsageGuard $guard,
        private readonly UsageRepository $usage,
        private readonly CostCalculator $costs,
        private readonly ProviderFactory $providers,
        private readonly TtsService $tts,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /api/sessions/{id}/audio (multipart: audio, duration_ms)
     * Transcribes a recorded answer. Returns an editable transcript; the learner then
     * submits it with /answer (input_mode=audio, transcript_id). The audio is deleted
     * immediately after transcription and never stored permanently.
     */
    public function transcribe(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $sessionId = $request->routeInt('id');
        $s = $this->sessions->findForUser($sessionId, $userId) ?? throw HttpException::notFound('Session not found.');
        if (!in_array($s['state'], SessionRepository::ANSWERABLE, true) || $s['current_exercise_id'] === null) {
            throw HttpException::conflict('not_answerable', 'There is no open exercise.');
        }

        $durationMs = isset($request->post['duration_ms']) && is_string($request->post['duration_ms']) && ctype_digit($request->post['duration_ms'])
            ? (int) $request->post['duration_ms'] : null;
        $validator = $this->container->has(AudioUploadValidator::class)
            ? $this->container->get(AudioUploadValidator::class)
            : new AudioUploadValidator($this->config->int('AUDIO_MAX_UPLOAD_BYTES', 5_242_880), $this->config->int('AUDIO_MAX_SECONDS', 60));
        $meta = $validator->validate($request->files['audio'] ?? null, $durationMs);

        $stt = $this->providers->transcriber();
        $decision = $this->guard->check($userId, $sessionId, 'stt', $this->costs->estimateStt($stt->model(), $meta['seconds']), $meta['seconds']);
        if (!$decision->allowed) {
            throw new HttpException(429, 'audio_limit', $decision->messageAr(), ['reason' => $decision->reason, 'fallback' => 'type_answer']);
        }

        // Prevent concurrent duplicate uploads for the same session.
        $token = bin2hex(random_bytes(16));
        if (!$this->sessions->lockForProcessing($s, $token)) {
            throw HttpException::conflict('answer_in_progress', 'This session is busy.');
        }
        /** @var TempAudioStore $store */
        $store = $this->container->has(TempAudioStore::class)
            ? $this->container->get(TempAudioStore::class)
            : new TempAudioStore($this->config->path('storage/tmp/audio'));
        $path = null;
        $started = microtime(true);
        try {
            $store->purgeOlderThan(3600);
            $path = $store->store($request->files['audio']['tmp_name'], $meta['mime']);
            try {
                $result = $stt->transcribe($path, $meta['mime'], $meta['seconds']);
            } catch (ProviderException $e) {
                $this->usage->record($userId, $sessionId, $stt->providerName(), 'stt', $stt->model(), 'error', new ProviderUsage(audioSeconds: $meta['seconds']), 0.0, (int) ((microtime(true) - $started) * 1000), $e->errorCode, $e->providerRequestId);
                $this->logger->warning('stt_provider_error', ['code' => $e->errorCode, 'detail' => $e->providerMessage]);
                throw new HttpException(503, 'stt_unavailable', $e->userMessageAr(), ['fallback' => 'type_answer', 'retryable' => $e->retryable]);
            }
        } finally {
            if ($path !== null) {
                $store->delete($path);
            }
            $this->sessions->unlock($sessionId, $token, (string) $s['state']);
        }

        $usage = $result->usage;
        $cost = $this->costs->stt($stt->model(), $usage->audioSeconds !== null || $usage->inputTokens !== null ? $usage : new ProviderUsage(audioSeconds: $meta['seconds']));
        $this->usage->record($userId, $sessionId, $stt->providerName(), 'stt', $stt->model(), 'ok', new ProviderUsage(
            inputTokens: $usage->inputTokens, outputTokens: $usage->outputTokens,
            audioSeconds: max((float) ($usage->audioSeconds ?? 0), $meta['seconds']),
            reportedByProvider: $usage->reportedByProvider, raw: $usage->raw,
        ), $cost, (int) ((microtime(true) - $started) * 1000), null, $result->providerRequestId);

        $text = mb_substr(trim($result->text), 0, 2000);
        if ($text === '') {
            throw new HttpException(422, 'audio_no_speech', 'لم نتعرّف على كلام في التسجيل. حاول مرة أخرى أو اكتب إجابتك.', ['fallback' => 'type_answer']);
        }
        $threshold = MasteryPolicy::fromConfig($this->config)->sttLowConfidence;
        $confidence = TranscriptRepository::confidenceLabel($result->averageProbability, $threshold);
        $this->transcripts->purgeStale();
        $id = $this->transcripts->create($userId, $sessionId, (int) $s['current_exercise_id'], (string) $s['phase'], (int) $s['transfer_index'], $text, $confidence, $result->averageProbability, $meta['seconds']);

        return Response::json([
            'ok' => true,
            'transcript' => [
                'id' => $id,
                'text' => $text,
                'confidence' => $confidence,
                'average_probability' => $result->averageProbability,
                'duration_seconds' => $meta['seconds'],
                'note_ar' => $confidence === 'low'
                    ? 'لسنا متأكدين من التعرّف على كل الكلمات. راجع النص وصحّحه قبل الإرسال.'
                    : 'راجع النص. إذا أخطأ التعرّف على كلمة فصحّحها قبل الإرسال.',
            ],
            'usage_warning' => $decision->warning,
        ]);
    }

    /** GET /api/turns/{id}/audio — speech for a tutor turn of the current user. */
    public function turn(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $turn = $this->turns->findForUser($request->routeInt('id'), $userId);
        if ($turn === null || $turn['role'] !== 'tutor' || $turn['text_de'] === null || trim((string) $turn['text_de']) === '') {
            throw HttpException::notFound('No audio for this message.');
        }
        $audio = $this->tts->audioFor((string) $turn['text_de'], $userId, (int) $turn['session_id']);
        return Response::file($audio['path'], $audio['mime'], 86400)->withHeader('X-Audio-Cache', $audio['cached'] ? 'hit' : 'miss');
    }

    /**
     * GET /api/turns/{id}/speech-text — the German text of an own tutor turn, for the
     * browser voice (TTS_PROVIDER=browser). Also used for listening texts that are hidden
     * on screen: the browser needs the words to speak them.
     */
    public function turnText(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $turn = $this->turns->findForUser($request->routeInt('id'), $userId);
        if ($turn === null || $turn['role'] !== 'tutor' || $turn['text_de'] === null || trim((string) $turn['text_de']) === '') {
            throw HttpException::notFound('No text for this message.');
        }
        return Response::json(['ok' => true, 'text_de' => (string) $turn['text_de']]);
    }

    /** GET /api/lessons/{id}/dialogue/{line}/audio — curriculum dialogue line. */
    public function dialogue(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $raw = $this->db->scalar("SELECT dialogue FROM lessons WHERE id = ? AND is_active = 1 AND kind = 'lesson'", [$request->routeInt('id')]);
        $lines = json_decode((string) ($raw ?? 'null'), true);
        $line = is_array($lines) ? ($lines[$request->routeInt('line')] ?? null) : null;
        if (!is_array($line) || !is_string($line['de'] ?? null)) {
            throw HttpException::notFound('No audio for this line.');
        }
        $audio = $this->tts->audioFor($line['de'], $userId, null);
        return Response::file($audio['path'], $audio['mime'], 86400)->withHeader('X-Audio-Cache', $audio['cached'] ? 'hit' : 'miss');
    }

    /** GET /api/vocabulary/{id}/audio — word (with article) and example sentence. */
    public function vocabulary(Request $request): Response
    {
        $userId = (int) $request->attribute('user')['id'];
        $v = $this->db->one('SELECT word_de, article, example_de FROM vocabulary WHERE id = ? AND is_active = 1', [$request->routeInt('id')]);
        if ($v === null) {
            throw HttpException::notFound('Word not found.');
        }
        $text = trim(($v['article'] !== null ? $v['article'] . ' ' : '') . $v['word_de'] . '. ' . ($v['example_de'] ?? ''));
        $audio = $this->tts->audioFor($text, $userId, null);
        return Response::file($audio['path'], $audio['mime'], 86400)->withHeader('X-Audio-Cache', $audio['cached'] ? 'hit' : 'miss');
    }
}
