<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/**
 * Validates decoded JSON against the subset of JSON Schema used by EvaluationSchema:
 * type (single or union), enum, required, properties, additionalProperties:false,
 * items, maxItems, maxLength, minimum, maximum.
 */
final class JsonSchemaValidator
{
    /** @param array<string,mixed> $schema @return list<string> errors (empty = valid) */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && !self::matchesType($value, $types)) {
            return ["$path: expected " . implode('|', $types) . ', got ' . get_debug_type($value)];
        }
        if ($value === null) {
            return [];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$path: value not in enum";
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            $errors[] = "$path: longer than {$schema['maxLength']}";
        }
        if ((is_int($value) || is_float($value))) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "$path: below minimum";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "$path: above maximum";
            }
        }
        if (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = "$path: more than {$schema['maxItems']} items";
            }
            foreach ($value as $i => $item) {
                array_push($errors, ...self::validate($item, $schema['items'] ?? [], "{$path}[$i]"));
            }
        }
        if (is_array($value) && in_array('object', $types, true)) {
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $value)) {
                    $errors[] = "$path.$req: required";
                }
            }
            $props = $schema['properties'] ?? [];
            foreach ($value as $key => $v) {
                if (!isset($props[$key])) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        $errors[] = "$path.$key: unexpected property";
                    }
                    continue;
                }
                array_push($errors, ...self::validate($v, $props[$key], "$path.$key"));
            }
        }
        return $errors;
    }

    /** @param list<string> $types */
    private static function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }
}
