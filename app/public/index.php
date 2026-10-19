<?php

declare(strict_types=1);

// PHP built-in dev server: let it serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $file = __DIR__ . (is_string($path) ? $path : '/');
    if ($file !== __FILE__ && is_file($file) && !str_contains((string) $path, '..')) {
        return false;
    }
}

// Default layout: this file lives in <app>/public. When public files were copied into
// a separate web root (e.g. Hostinger public_html), app-path.php returns the app directory.
$appRoot = dirname(__DIR__);
if (is_file(__DIR__ . '/app-path.php')) {
    $appRoot = (string) require __DIR__ . '/app-path.php';
}

require $appRoot . '/bootstrap/autoload.php';

$config = GermanCoach\Core\Config::load($appRoot)->with(['PUBLIC_DIR' => __DIR__]);
if (!$config->debug()) {
    ini_set('display_errors', '0');
}
$app = new GermanCoach\App($config);
$app->handle(GermanCoach\Core\Request::fromGlobals())->send();
