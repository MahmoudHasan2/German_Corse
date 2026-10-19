<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Config;
use GermanCoach\Core\Container;
use GermanCoach\Core\Database;
use GermanCoach\Core\Migrator;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;

/**
 * GET /api/health — operational status with booleans only. No versions,
 * paths, hostnames or configuration values are revealed.
 */
final class HealthController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request): Response
    {
        /** @var Config $config */
        $config = $this->container->get(Config::class);

        $checks = [
            'php_version_supported' => PHP_VERSION_ID >= 80200,
            'extensions_loaded' => self::extensionsLoaded(),
            'storage_writable' => self::storageWritable($config),
            'database_reachable' => false,
            'migrations_current' => false,
            'ai_configured' => self::aiConfigured($config),
        ];

        try {
            /** @var Database $db */
            $db = $this->container->get(Database::class);
            $db->scalar('SELECT 1');
            $checks['database_reachable'] = true;
            $migrator = new Migrator($db, $config->path('database/migrations'));
            $checks['migrations_current'] = $migrator->pending() === [];
        } catch (\Throwable) {
            // Reported as false; details stay in no response.
        }

        $critical = ['php_version_supported', 'extensions_loaded', 'storage_writable', 'database_reachable', 'migrations_current'];
        $healthy = array_reduce($critical, static fn (bool $ok, string $k) => $ok && $checks[$k], true);

        return Response::json([
            'ok' => $healthy,
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'time' => gmdate('c'),
        ], $healthy ? 200 : 503);
    }

    public static function extensionsLoaded(): bool
    {
        foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'fileinfo'] as $ext) {
            if (!extension_loaded($ext)) {
                return false;
            }
        }
        return true;
    }

    public static function storageWritable(Config $config): bool
    {
        foreach (['storage/logs', 'storage/tmp/audio', 'storage/cache/tts'] as $dir) {
            $path = $config->path($dir);
            if (!is_dir($path) || !is_writable($path)) {
                return false;
            }
        }
        return true;
    }

    public static function aiConfigured(Config $config): bool
    {
        if (!$config->bool('AI_ENABLED', true)) {
            return false;
        }
        return (new \GermanCoach\Ai\ProviderFactory($config))->isConfigured('tutor');
    }
}
