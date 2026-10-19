<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

/**
 * Pricing metadata loaded from config/pricing.json. Unknown models use the
 * conservative "fallback" rates (over-estimating is safer for budgets).
 */
final class PricingTable
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return new self(is_array($data) ? $data : []);
    }

    /** @return array<string,float> */
    public function rates(string $model, string $service): array
    {
        $m = $this->data['models'][$model] ?? null;
        if (is_array($m) && ($m['service'] ?? $service) === $service) {
            return array_map('floatval', array_filter($m, 'is_numeric'));
        }
        $fb = $this->data['fallback'][$service] ?? [];
        return array_map('floatval', array_filter(is_array($fb) ? $fb : [], 'is_numeric'));
    }

    public function isKnown(string $model): bool
    {
        return isset($this->data['models'][$model]);
    }

    public function asOf(): string
    {
        return (string) ($this->data['as_of'] ?? 'unknown');
    }
}
