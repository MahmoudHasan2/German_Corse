<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Config;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;

/**
 * Serves the single HTML shell. All data is loaded by ES modules from the JSON API,
 * so the shell contains no user data. Asset URLs carry a content hash for cache busting.
 */
final class ShellController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(Request $request): Response
    {
        $template = (string) file_get_contents($this->config->path('templates/shell.html'));
        $public = $this->publicDir();
        $version = substr(hash('sha256', implode('|', array_map(
            static fn (string $f) => is_file($f) ? (string) filemtime($f) : '0',
            [$public . '/assets/css/app.css', $public . '/assets/js/app.js']
        ))), 0, 10);
        $html = str_replace('{{v}}', $version, $template);
        return Response::html($html);
    }

    private function publicDir(): string
    {
        $configured = $this->config->string('PUBLIC_DIR');
        return $configured !== '' ? $configured : $this->config->path('public');
    }
}
