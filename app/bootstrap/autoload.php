<?php

declare(strict_types=1);

/*
 * Uses Composer's autoloader when present (local development, tests).
 * On hosting without Composer, falls back to a minimal PSR-4 loader:
 * the application has no runtime Composer dependencies.
 */

$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GermanCoach\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (str_contains($relative, '..')) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
