<?php

declare(strict_types=1);

namespace GermanCoach\Mastery;

/** Snapshot of the session's progress on the current exercise. Immutable input to the engine. */
final class MasteryState
{
    /** @param list<list<string>> $failedCategories error categories of each failed attempt on the current item */
    public function __construct(
        public readonly string $mode,               // lesson | review | assessment
        public readonly string $phase,              // main | transfer
        public readonly int $transferIndex,
        public readonly int $transferCount,
        public readonly int $itemAttempts,          // attempts on the current item before this one
        public readonly int $itemFailures,
        public readonly int $strategyLevel,         // 0..3
        public readonly bool $exerciseRequiresTransfer,
        public readonly bool $mainAssisted,
        public readonly array $failedCategories = [],
        public readonly int $exerciseAttempts = 0,  // all attempts on this exercise in this session (any phase)
    ) {
    }
}
