<?php

declare(strict_types=1);

namespace GermanCoach\Mastery;

use GermanCoach\Core\Config;

/** Configurable thresholds of the deterministic mastery rules. */
final class MasteryPolicy
{
    public function __construct(
        public readonly bool $requireTransfer = true,
        public readonly int $strategyChangeAfter = 2,
        public readonly int $maxAttempts = 5,
        public readonly float $minConfidence = 0.6,
        public readonly bool $minorErrorsPass = true,
        public readonly float $copySimilarity = 0.9,
        public readonly float $sttLowConfidence = 0.55,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        return new self(
            $c->bool('MASTERY_REQUIRE_TRANSFER', true),
            max(1, $c->int('MASTERY_STRATEGY_CHANGE_AFTER', 2)),
            max(2, $c->int('MASTERY_MAX_ATTEMPTS', 5)),
            min(1.0, max(0.0, $c->float('MASTERY_MIN_CONFIDENCE', 0.6))),
            $c->bool('MASTERY_MINOR_ERRORS_PASS', true),
            min(1.0, max(0.5, $c->float('MASTERY_COPY_SIMILARITY', 0.9))),
            min(1.0, max(0.0, $c->float('STT_LOW_CONFIDENCE_THRESHOLD', 0.55))),
        );
    }
}
