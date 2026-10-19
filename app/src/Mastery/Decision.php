<?php

declare(strict_types=1);

namespace GermanCoach\Mastery;

/** The engine's verdict on what happens next. Persisted for auditability. */
final class Decision
{
    public const RETRY = 'retry';
    public const RETRY_NEW_STRATEGY = 'retry_new_strategy';
    public const RETRY_UNCERTAIN = 'retry_uncertain';
    public const CONFIRM_TRANSCRIPT = 'confirm_transcript';
    public const REQUIRE_TRANSFER = 'require_transfer';
    public const NEXT_TRANSFER = 'next_transfer';
    public const MASTERED = 'mastered';
    public const NEEDS_REVIEW = 'needs_review';
    public const DEFERRED = 'deferred_to_review';
    public const SKIPPED = 'skipped';
    public const ASSESSMENT_RECORDED = 'assessment_recorded';
    public const OFFLINE_SELF_CHECK = 'offline_self_check';

    public function __construct(
        public readonly string $code,
        public readonly string $reason,
        public readonly string $nextState,          // session state when the exercise stays
        public readonly string $nextPhase,
        public readonly int $nextTransferIndex,
        public readonly bool $advance,              // move on to the next exercise
        public readonly ?string $exerciseStatus,    // new user_progress.status or null = unchanged
        public readonly int $strategyLevel,
        public readonly bool $resetItem,            // new item → attempt counters start at 0
        public readonly bool $countsAsFailure,
        public readonly int $reviewGrade = -1,      // 0..5 for the scheduler, -1 = no scheduling
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'reason' => $this->reason,
            'next_state' => $this->nextState,
            'next_phase' => $this->nextPhase,
            'next_transfer_index' => $this->nextTransferIndex,
            'advance' => $this->advance,
            'exercise_status' => $this->exerciseStatus,
            'strategy_level' => $this->strategyLevel,
            'review_grade' => $this->reviewGrade,
        ];
    }
}
