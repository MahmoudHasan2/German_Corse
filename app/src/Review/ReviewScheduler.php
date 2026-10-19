<?php

declare(strict_types=1);

namespace GermanCoach\Review;

use DateTimeImmutable;

/**
 * Explainable SM-2-style scheduler.
 *
 * Grade (0–5) is derived by the mastery engine from correctness, hints, transfer
 * performance and failures. This class adds: repeated-error penalty and credit for
 * successfully remembering an item after a longer-than-planned gap.
 */
final class ReviewScheduler
{
    public const MIN_EASE = 1.3;
    public const MAX_INTERVAL_DAYS = 180;

    /**
     * @param array{interval_days:int,ease:float,repetitions:int,lapses:int,last_reviewed_at:?DateTimeImmutable} $prev
     * @return array{interval_days:int,ease:float,repetitions:int,lapses:int,due_at:DateTimeImmutable,reason:string}
     */
    public function schedule(array $prev, int $grade, DateTimeImmutable $now, int $repeatedErrors = 0): array
    {
        $grade = max(0, min(5, $grade));
        $ease = (float) $prev['ease'];
        $reps = (int) $prev['repetitions'];
        $lapses = (int) $prev['lapses'];
        $interval = (int) $prev['interval_days'];
        $reasons = [];

        $ease = max(self::MIN_EASE, $ease + (0.1 - (5 - $grade) * (0.08 + (5 - $grade) * 0.02)));

        if ($grade < 3) {
            $reps = 0;
            $lapses++;
            $interval = 1;
            $reasons[] = match ($grade) {
                0 => 'not solved → review tomorrow',
                1 => 'skipped or deferred → review tomorrow',
                default => 'solved only with help → review tomorrow',
            };
        } else {
            $reps++;
            $elapsed = $prev['last_reviewed_at'] !== null
                ? max(0, (int) floor(($now->getTimestamp() - $prev['last_reviewed_at']->getTimestamp()) / 86400))
                : 0;
            $base = max($interval, $elapsed);
            if ($elapsed > $interval && $interval > 0) {
                $reasons[] = "remembered after {$elapsed} days (planned {$interval})";
            }
            // Grade 3 = solved with help ("hard"): grow the interval much more slowly.
            $hardFactor = $grade === 3 ? 0.6 : 1.0;
            $interval = match (true) {
                $reps === 1 => 1,
                $reps === 2 => [3 => 2, 4 => 3, 5 => 4][$grade],
                default => (int) round($base * $ease * $hardFactor),
            };
            $reasons[] = "grade $grade, repetition $reps" . ($grade === 3 ? ' (with help)' : '');
        }

        if ($repeatedErrors >= 2 && $interval > 1) {
            $interval = max(1, (int) floor($interval * 0.6));
            $reasons[] = 'same mistake repeated → shorter interval';
        }
        $interval = min(self::MAX_INTERVAL_DAYS, max(1, $interval));

        return [
            'interval_days' => $interval,
            'ease' => round($ease, 2),
            'repetitions' => $reps,
            'lapses' => $lapses,
            'due_at' => $now->modify("+{$interval} days"),
            'reason' => implode('; ', $reasons),
        ];
    }

    /** @return array{interval_days:int,ease:float,repetitions:int,lapses:int,last_reviewed_at:?DateTimeImmutable} */
    public static function initial(): array
    {
        return ['interval_days' => 0, 'ease' => 2.5, 'repetitions' => 0, 'lapses' => 0, 'last_reviewed_at' => null];
    }
}
