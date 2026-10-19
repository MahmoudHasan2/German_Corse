<?php

declare(strict_types=1);

namespace GermanCoach;

use GermanCoach\Ai\ProviderFactory;
use GermanCoach\Core\Config;
use GermanCoach\Core\Container;
use GermanCoach\Usage\PricingTable;

/** Service definitions that need configuration values (everything else is autowired). */
final class Services
{
    public static function register(Container $c, Config $config): void
    {
        // Everything that (transitively) holds the session or the current user.
        $c->markRequestScoped(
            Core\Session\SessionStore::class,
            Auth\AuthService::class,
            Http\Controllers\AuthController::class,
            Http\Controllers\SetupController::class,
            Http\Controllers\SettingsController::class,
        );
        $c->set(ProviderFactory::class, static fn () => new ProviderFactory($config));
        $c->set(PricingTable::class, static function () use ($config) {
            $file = $config->string('PRICING_FILE', 'config/pricing.json');
            $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) ? $file : $config->path($file);
            return PricingTable::fromFile($path);
        });
    }
}
