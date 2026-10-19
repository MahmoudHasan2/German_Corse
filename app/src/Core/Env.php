<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Minimal .env parser. Supports KEY=value, "double quoted" (with \n, \", \\ escapes),
 * 'single quoted' (literal) values and # comments. No variable interpolation on purpose.
 */
final class Env
{
    /** @return array<string,string> */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = file_get_contents($path);
        return $content === false ? [] : self::parse($content);
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $result = [];
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $result[$key] = self::parseValue(trim(substr($line, $eq + 1)));
        }
        return $result;
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = self::findClosingQuote($raw, $quote);
            if ($end !== null) {
                $inner = substr($raw, 1, $end - 1);
                if ($quote === '"') {
                    $inner = strtr($inner, ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\', '\\t' => "\t"]);
                }
                return $inner;
            }
        }
        // Unquoted: strip inline comment introduced by " #".
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }
        return trim($raw);
    }

    private static function findClosingQuote(string $raw, string $quote): ?int
    {
        $len = strlen($raw);
        for ($i = 1; $i < $len; $i++) {
            if ($raw[$i] === '\\' && $quote === '"') {
                $i++;
                continue;
            }
            if ($raw[$i] === $quote) {
                return $i;
            }
        }
        return null;
    }
}
