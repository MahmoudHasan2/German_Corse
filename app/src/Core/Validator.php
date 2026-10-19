<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Collects field errors and throws a single 422 HttpException.
 * Values are returned already normalized (trimmed, typed).
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'required';
            }
            return null;
        }
        if (!is_string($value)) {
            $this->errors[$field] = 'must_be_string';
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $this->errors[$field] = 'invalid_encoding';
            return null;
        }
        // Remove control characters except newline and tab.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        $len = mb_strlen($value);
        if ($len < $min) {
            $this->errors[$field] = $required || $len > 0 ? 'too_short' : 'required';
            return null;
        }
        if ($len > $max) {
            $this->errors[$field] = 'too_long';
            return null;
        }
        return $value;
    }

    public function email(string $field): ?string
    {
        $value = $this->string($field, 3, 190);
        if ($value === null) {
            return null;
        }
        $value = mb_strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'invalid_email';
            return null;
        }
        return $value;
    }

    public function int(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'required';
            }
            return null;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            $this->errors[$field] = 'must_be_integer';
            return null;
        }
        if ($value < $min || $value > $max) {
            $this->errors[$field] = 'out_of_range';
            return null;
        }
        return $value;
    }

    public function bool(string $field, bool $default = false): bool
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'off', ''], true)) {
            return false;
        }
        $this->errors[$field] = 'must_be_boolean';
        return $default;
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, ?string $default = null): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($default === null) {
                $this->errors[$field] = 'required';
            }
            return $default;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] = 'invalid_choice';
            return $default;
        }
        return $value;
    }

    /** Client-generated idempotency key: 8–64 chars of [A-Za-z0-9_-]. */
    public function submissionId(string $field = 'client_submission_id'): ?string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $value)) {
            $this->errors[$field] = 'invalid_submission_id';
            return null;
        }
        return $value;
    }

    public function addError(string $field, string $code): void
    {
        $this->errors[$field] = $code;
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
