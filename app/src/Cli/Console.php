<?php

declare(strict_types=1);

namespace GermanCoach\Cli;

use GermanCoach\App;
use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Core\Migrator;
use GermanCoach\Http\Controllers\HealthController;

final class Console
{
    private App $app;

    public function __construct(private readonly Config $config)
    {
        $this->app = new App($config);
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $options = self::options(array_slice($args, 1));
        try {
            return match ($command) {
                'migrate' => $this->migrate(),
                'status' => $this->status(),
                'check' => $this->check(),
                default => $this->extended($command, $options),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** @param array<string,string> $options */
    private function extended(string $command, array $options): int
    {
        $commands = Commands::all($this->app);
        if (isset($commands[$command])) {
            return $commands[$command]($options, $this);
        }
        $this->line('German AI Coach CLI');
        $this->line('  migrate                         Apply pending database migrations');
        $this->line('  status                          Show migration status');
        $this->line('  check                           Check PHP, extensions, storage, DB, AI config');
        foreach (Commands::help() as $line) {
            $this->line('  ' . $line);
        }
        return $command === 'help' ? 0 : 1;
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->app->container()->get(Database::class), $this->config->path('database/migrations'));
    }

    private function migrate(): int
    {
        $applied = $this->migrator()->migrate();
        $this->line($applied === [] ? 'Nothing to migrate.' : 'Applied: ' . implode(', ', $applied));
        foreach ($this->migrator()->modified() as $v) {
            $this->line("WARNING: migration $v changed after it was applied. Add a new migration instead of editing old ones.");
        }
        return 0;
    }

    private function status(): int
    {
        $m = $this->migrator();
        $applied = $m->applied();
        foreach (array_keys($m->available()) as $v) {
            $this->line(sprintf('%-40s %s', $v, isset($applied[$v]) ? 'applied' : 'PENDING'));
        }
        return 0;
    }

    private function check(): int
    {
        $ok = true;
        $report = function (string $label, bool $pass, string $hint = '') use (&$ok): void {
            $ok = $ok && $pass;
            $this->line(sprintf('[%s] %s%s', $pass ? ' OK ' : 'FAIL', $label, $pass || $hint === '' ? '' : ' — ' . $hint));
        };
        $report('PHP >= 8.2 (found ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80200, 'select PHP 8.2+ in hPanel');
        foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'fileinfo'] as $ext) {
            $report("extension $ext", extension_loaded($ext), 'enable it in hPanel → PHP Configuration');
        }
        $report('storage writable', HealthController::storageWritable($this->config), 'chmod 755/775 storage subfolders');
        $report('.env present', is_file($this->config->path('.env')), 'copy .env.example to .env');
        try {
            $db = $this->app->container()->get(Database::class);
            $db->scalar('SELECT 1');
            $report('database connection', true);
            $report('migrations current', $this->migrator()->pending() === [], 'run: php bin/console.php migrate');
        } catch (\Throwable $e) {
            $report('database connection', false, 'check DB_* settings');
        }
        $report('AI provider configured', HealthController::aiConfigured($this->config), 'set TUTOR_PROVIDER and its API key (OPENAI_/GEMINI_/GROQ_API_KEY), or AI_PROVIDER=fake for a demo');
        $report('HTTPS URL configured', str_starts_with($this->config->string('APP_URL'), 'https://'), 'microphone needs HTTPS');
        $token = $this->config->string('SETUP_TOKEN');
        $report('SETUP_TOKEN empty or strong', $token === '' || strlen($token) >= 24, 'use 24+ random characters');
        return $ok ? 0 : 1;
    }

    public function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    /** @param list<string> $args @return array<string,string> */
    private static function options(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
                $out[$m[1]] = $m[2] ?? '1';
            }
        }
        return $out;
    }

    public function app(): App
    {
        return $this->app;
    }
}
