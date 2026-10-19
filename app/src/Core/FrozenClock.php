<?php

declare(strict_types=1);

namespace GermanCoach\Core;

use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-01-15 10:00:00')
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
