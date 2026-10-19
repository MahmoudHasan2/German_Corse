<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Updates KEY=value lines in a .env file in place (other lines and comments are kept,
 * missing keys are appended). Values must be plain tokens: no quotes, spaces or newlines.
 */
final class EnvWriter
{
    /** @param array<string,string> $values */
    public static function set(string $path, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) || !preg_match('/^[A-Za-z0-9_.\/:-]*$/', $value)) {
                throw new \InvalidArgumentException("Unsafe .env entry: $key");
            }
        }
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', rtrim($content, "\r\n")) ?: []);
        $pending = $values;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) && array_key_exists($m[1], $pending)) {
                $lines[$i] = $m[1] . '=' . $pending[$m[1]];
                unset($pending[$m[1]]);
            }
        }
        foreach ($pending as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write ' . basename($path));
        }
    }
}
