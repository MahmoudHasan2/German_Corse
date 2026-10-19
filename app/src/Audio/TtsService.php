<?php

declare(strict_types=1);

namespace GermanCoach\Audio;

use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderFactory;
use GermanCoach\Core\Config;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Logger;
use GermanCoach\Usage\CostCalculator;
use GermanCoach\Usage\UsageGuard;
use GermanCoach\Usage\UsageRepository;

/**
 * Text-to-speech with a content-addressed disk cache (storage/cache/tts). The same
 * German text with the same voice settings is synthesized once and then served for
 * free — curriculum prompts are shared across sessions and users.
 * Only server-known text (curriculum, stored tutor turns) is ever synthesized.
 */
final class TtsService
{
    public const MAX_CHARS = 1200;

    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly UsageGuard $guard,
        private readonly UsageRepository $usage,
        private readonly CostCalculator $costs,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{path:string,mime:string,cached:bool} */
    public function audioFor(string $text, int $userId, ?int $sessionId): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_CHARS) {
            throw new HttpException(422, 'tts_text_invalid', 'This text cannot be read aloud.');
        }
        if ($this->providers->provider('tts') === 'browser') {
            throw new HttpException(409, 'tts_browser_mode', 'Speech is produced by the browser in this configuration.', ['fallback' => 'browser_speech']);
        }
        $tts = $this->providers->speech();
        $ext = match ($tts->mimeType()) {
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            default => 'mp3',
        };
        $dir = $this->config->path('storage/cache/tts');
        $path = $dir . '/' . hash('sha256', $tts->cacheKeyPrefix() . '|' . $text) . '.' . $ext;
        if (is_file($path) && filesize($path) > 0) {
            @touch($path);
            return ['path' => $path, 'mime' => $tts->mimeType(), 'cached' => true];
        }

        $chars = mb_strlen($text);
        $decision = $this->guard->check($userId, $sessionId, 'tts', $this->costs->tts($tts->model(), $chars));
        if (!$decision->allowed) {
            throw new HttpException(429, 'tts_unavailable', $decision->messageAr(), ['reason' => $decision->reason, 'fallback' => 'browser_speech']);
        }

        $started = microtime(true);
        try {
            $result = $tts->synthesize($text);
        } catch (ProviderException $e) {
            $this->usage->record($userId, $sessionId, $tts->providerName(), 'tts', $tts->model(), 'error', new \GermanCoach\Ai\ProviderUsage(characters: $chars), 0.0, (int) ((microtime(true) - $started) * 1000), $e->errorCode, $e->providerRequestId);
            $this->logger->warning('tts_provider_error', ['code' => $e->errorCode, 'detail' => $e->providerMessage]);
            throw new HttpException(503, 'tts_unavailable', $e->userMessageAr(), ['fallback' => 'browser_speech']);
        }
        $this->usage->record($userId, $sessionId, $tts->providerName(), 'tts', $tts->model(), 'ok', $result->usage, $this->costs->tts($tts->model(), $chars), (int) ((microtime(true) - $started) * 1000), null, $result->providerRequestId);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $result->audio) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpException(500, 'tts_cache_failed', 'Audio could not be stored.');
        }
        if (random_int(1, 50) === 1) {
            $this->purgeCache($dir);
        }
        return ['path' => $path, 'mime' => $result->mimeType, 'cached' => false];
    }

    /** Keeps the cache bounded: removes entries unused for 90 days. */
    private function purgeCache(string $dir): void
    {
        foreach (glob($dir . '/*.*') ?: [] as $f) {
            if (is_file($f) && fileatime($f) < time() - 90 * 86400 && filemtime($f) < time() - 90 * 86400) {
                @unlink($f);
            }
        }
    }
}
