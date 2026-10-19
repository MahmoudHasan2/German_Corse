<?php

declare(strict_types=1);

namespace GermanCoach\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Injected time source (UTC) so scheduling and limits are testable. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function nowSql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public static function toSql(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromSql(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
}
