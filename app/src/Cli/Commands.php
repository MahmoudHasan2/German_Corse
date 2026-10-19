<?php

declare(strict_types=1);

namespace GermanCoach\Cli;

use GermanCoach\App;
use GermanCoach\Audio\TempAudioStore;
use GermanCoach\Auth\AuthService;
use GermanCoach\Auth\UserRepository;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Config;
use GermanCoach\Core\Database;
use GermanCoach\Curriculum\CurriculumImporter;
use GermanCoach\Learning\TranscriptRepository;
use GermanCoach\Usage\AppSettings;
use GermanCoach\Usage\RateLimiter;
use GermanCoach\Usage\UsageGuard;

/** Additional CLI commands. Each has a web/manual alternative documented in docs/DEPLOYMENT_HOSTINGER.md. */
final class Commands
{
    /** @return array<string,\Closure(array<string,string>,Console):int> */
    public static function all(App $app): array
    {
        $c = $app->container();
        return [
            'seed' => static function (array $o, Console $out) use ($c): int {
                $config = $c->get(Config::class);
                $stats = (new CurriculumImporter($c->get(Database::class)))->importDirectory($config->path('curriculum'));
                $out->line(sprintf('Curriculum imported: %d lessons, %d exercises, %d words changed; %d deactivated.', $stats['lessons'], $stats['exercises'], $stats['vocabulary'], $stats['deactivated']));
                return 0;
            },
            'create-user' => static function (array $o, Console $out) use ($c): int {
                $email = mb_strtolower(trim($o['email'] ?? ''));
                $name = trim($o['name'] ?? 'Learner');
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $out->line('Usage: create-user --email=you@example.com --name="Your Name" [--admin]  (password is read from STDIN or prompted)');
                    return 1;
                }
                $out->line('Password (min. 10 characters):');
                $password = trim((string) fgets(STDIN));
                if (($err = AuthService::validatePassword($password)) !== null) {
                    $out->line("Rejected: $err");
                    return 1;
                }
                $users = new UserRepository($c->get(Database::class));
                if ($users->findByEmail($email) !== null) {
                    $out->line('A user with this e-mail already exists.');
                    return 1;
                }
                $id = $users->create($email, $password, $name, isset($o['admin']) ? 'admin' : 'learner');
                $out->line("Created user #$id ($email).");
                return 0;
            },
            'cleanup' => static function (array $o, Console $out) use ($c): int {
                $config = $c->get(Config::class);
                $audio = (new TempAudioStore($config->path('storage/tmp/audio')))->purgeOlderThan(3600);
                $transcripts = $c->get(TranscriptRepository::class)->purgeStale();
                $limits = $c->get(RateLimiter::class)->purgeExpired();
                $out->line("Removed: $audio temp recordings, $transcripts unused transcripts, $limits expired rate-limit rows.");
                return 0;
            },
            'usage' => static function (array $o, Console $out) use ($c): int {
                $db = $c->get(Database::class);
                $clock = $c->get(Clock::class);
                $monthStart = $clock->now()->format('Y-m-01 00:00:00');
                $rows = $db->all('SELECT service, model, COUNT(*) n, SUM(estimated_cost_usd) cost FROM api_usage WHERE created_at >= ? GROUP BY service, model', [$monthStart]);
                $out->line('This month (UTC, estimated):');
                foreach ($rows as $r) {
                    $out->line(sprintf('  %-5s %-28s %6d requests  $%.4f', $r['service'], $r['model'], $r['n'], $r['cost']));
                }
                $out->line(sprintf('Monthly budget: $%.2f (AI stops at %d%%)', $c->get(Config::class)->float('LIMIT_MONTHLY_BUDGET_USD'), (int) round(100 * $c->get(Config::class)->float('LIMIT_EMERGENCY_THRESHOLD'))));
                return 0;
            },
            'ai-off' => static function (array $o, Console $out) use ($c): int {
                $c->get(AppSettings::class)->set(UsageGuard::KILL_SWITCH, '1');
                $out->line('AI requests disabled for all users.');
                return 0;
            },
            'ai-on' => static function (array $o, Console $out) use ($c): int {
                $s = $c->get(AppSettings::class);
                $s->delete(UsageGuard::KILL_SWITCH);
                $s->delete(UsageGuard::EMERGENCY_MARKER);
                $out->line('AI requests enabled (budget limits still apply).');
                return 0;
            },
        ];
    }

    /** @return list<string> */
    public static function help(): array
    {
        return [
            'seed                            Import/update the curriculum from curriculum/*.json',
            'create-user --email= --name= [--admin]   Create an account (password via STDIN)',
            'cleanup                         Purge temp audio, stale transcripts, expired rate limits',
            'usage                           Show this month\'s estimated AI usage',
            'ai-off | ai-on                  Kill switch for all AI requests',
        ];
    }
}
