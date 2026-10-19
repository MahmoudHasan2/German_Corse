<?php

declare(strict_types=1);

/*
 * German AI Coach CLI. Usage:  php bin/console.php <command> [options]
 * Hosts without SSH can do the same through the token-protected web setup (/#/setup).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appRoot = dirname(__DIR__);
require $appRoot . '/bootstrap/autoload.php';

use GermanCoach\Cli\Console;
use GermanCoach\Core\Config;

$config = Config::load($appRoot);
exit((new Console($config))->run(array_slice($argv, 1)));
