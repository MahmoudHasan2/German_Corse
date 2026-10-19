<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Typed, read-only configuration built from defaults (config/defaults.php),
 * the .env file, and real environment variables (highest priority).
 */
final class Config
{
    /** @param array<string,string|int|float|bool|null> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(string $appRoot, ?string $envFile = null): self
    {
        /** @var array<string,string|int|float|bool|null> $defaults */
        $defaults = require $appRoot . '/config/defaults.php';
        $file = Env::parseFile($envFile ?? ($appRoot . '/.env'));
        $values = array_merge($defaults, $file);
        foreach (array_keys($values) as $key) {
            $real = getenv((string) $key);
            if ($real !== false && $real !== '') {
                $values[$key] = $real;
            }
        }
        $values['APP_ROOT'] = $appRoot;
        return new self($values);
    }

    /** @param array<string,string|int|float|bool|null> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->values, $overrides));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null && $this->values[$key] !== '';
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->values[$key] ?? null;
        return ($v === null || $v === '') ? $default : (string) $v;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        return (int) $v;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->values[$key] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        return (float) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->values[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function appRoot(): string
    {
        return (string) $this->values['APP_ROOT'];
    }

    public function path(string $relative): string
    {
        return rtrim($this->appRoot(), '/\\') . '/' . ltrim($relative, '/\\');
    }

    public function isProduction(): bool
    {
        return $this->string('APP_ENV', 'production') === 'production';
    }

    public function isTesting(): bool
    {
        return $this->string('APP_ENV') === 'testing';
    }

    public function debug(): bool
    {
        return !$this->isProduction() && $this->bool('APP_DEBUG');
    }
}
