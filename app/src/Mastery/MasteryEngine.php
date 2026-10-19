<?php

declare(strict_types=1);

namespace GermanCoach\Mastery;

/**
 * Deterministic progression rules. Pure: no I/O, no AI, no clock.
 * The AI only supplies an evaluation; this engine alone decides whether the learner
 * retries, gets a transfer task, masters the exercise, or moves on.
 *
 * Rules (see PROJECT_PLAN.md §3.2):
 *  - "independent" = first attempt on the current item, no hint, not a copied correction.
 *  - Mastery requires an independent success: on the main item (when no transfer is
 *    required) or on a transfer item.
 *  - Assisted successes are recorded but never count as mastery on their own.
 *  - Failures escalate the explanation strategy and end in a review deferral after
 *    maxAttempts, so the learner is never trapped.
 */
final class MasteryEngine
{
    public function __construct(private readonly MasteryPolicy $policy)
    {
    }

    /** @param list<string> $errorCategories categories found in this attempt */
    public function decide(MasteryState $s, string $outcome, string $reason, bool $independent, array $errorCategories = []): Decision
    {
        if ($outcome === 'confirm_transcript') {
            return $this->stay($s, Decision::CONFIRM_TRANSCRIPT, 'transcription_uncertain', false);
        }

        if ($s->mode === 'assessment') {
            return new Decision(Decision::ASSESSMENT_RECORDED, $outcome, 'awaiting_answer', 'main', 0, true, null, 0, true, false);
        }

        // Hard cap across all outcomes (uncertain, failures, transfer retries): never loop forever.
        if ($outcome !== 'success' && $outcome !== 'unavailable' && $s->exerciseAttempts + 1 >= $this->policy->maxAttempts * 2) {
            return new Decision(Decision::DEFERRED, 'attempt_cap_reached', 'awaiting_answer', 'main', 0, true, 'needs_review', 0, true, $outcome === 'failure', 1);
        }

        if ($outcome === 'unavailable') {
            return $this->stay($s, Decision::OFFLINE_SELF_CHECK, 'ai_unavailable', false);
        }
        if ($outcome === 'uncertain') {
            return $this->stay($s, Decision::RETRY_UNCERTAIN, $reason, false);
        }
        if ($outcome === 'success') {
            return $this->onSuccess($s, $reason, $independent);
        }
        return $this->onFailure($s, $reason, $errorCategories);
    }

    public function skip(MasteryState $s): Decision
    {
        return new Decision(Decision::SKIPPED, 'learner_skipped', 'awaiting_answer', 'main', 0, true, 'skipped', 0, true, false, 1);
    }

    private function onSuccess(MasteryState $s, string $verdict, bool $independent): Decision
    {
        $clean = $verdict !== 'minor_errors';
        $transferAvailable = $s->transferCount > 0;
        $transferRequired = $s->mode === 'lesson' && $this->policy->requireTransfer && $s->exerciseRequiresTransfer;

        if ($s->phase === 'main') {
            if ($independent && !($transferRequired && $transferAvailable)) {
                return $this->mastered('independent_main_success', $clean ? 5 : 4);
            }
            if ($transferAvailable && ($s->mode === 'lesson' || !$independent)) {
                return new Decision(
                    Decision::REQUIRE_TRANSFER,
                    $independent ? 'transfer_required_by_policy' : 'assisted_success_needs_transfer',
                    'awaiting_transfer_task',
                    'transfer',
                    0,
                    false,
                    'in_progress',
                    0,
                    true,
                    false,
                );
            }
            // Assisted success with no transfer task to prove independence.
            return new Decision(Decision::NEEDS_REVIEW, 'assisted_success_no_transfer', 'awaiting_answer', 'main', 0, true, 'needs_review', 0, true, false, 2);
        }

        // Transfer phase.
        if ($independent) {
            return $this->mastered($s->mainAssisted ? 'transfer_success_after_assisted_main' : 'transfer_success', $s->mainAssisted ? 4 : ($clean ? 5 : 4));
        }
        if ($s->transferIndex + 1 < $s->transferCount) {
            return new Decision(Decision::NEXT_TRANSFER, 'assisted_transfer_success', 'awaiting_transfer_task', 'transfer', $s->transferIndex + 1, false, 'in_progress', 0, true, false);
        }
        return new Decision(Decision::NEEDS_REVIEW, 'no_independent_transfer_success', 'awaiting_answer', 'main', 0, true, 'needs_review', 0, true, false, 2);
    }

    /** @param list<string> $categories */
    private function onFailure(MasteryState $s, string $reason, array $categories): Decision
    {
        $failures = $s->itemFailures + 1;
        if ($failures >= $this->policy->maxAttempts) {
            return new Decision(Decision::DEFERRED, 'max_attempts_reached', 'awaiting_answer', 'main', 0, true, 'needs_review', 0, true, true, 1);
        }
        $repeated = false;
        foreach ($s->failedCategories as $previous) {
            if (array_intersect($previous, $categories) !== []) {
                $repeated = true;
                break;
            }
        }
        if ($failures >= $this->policy->strategyChangeAfter || $repeated) {
            $level = min(3, $s->strategyLevel + 1);
            return $this->stay($s, Decision::RETRY_NEW_STRATEGY, $repeated ? 'repeated_error_category' : 'retry_threshold_reached', true, $level);
        }
        return $this->stay($s, Decision::RETRY, $reason, true);
    }

    private function mastered(string $reason, int $grade): Decision
    {
        return new Decision(Decision::MASTERED, $reason, 'awaiting_answer', 'main', 0, true, 'provisionally_mastered', 0, true, false, $grade);
    }

    private function stay(MasteryState $s, string $code, string $reason, bool $failure, ?int $strategy = null): Decision
    {
        $state = $s->phase === 'transfer' ? 'awaiting_transfer_task' : 'needs_retry';
        return new Decision($code, $reason, $state, $s->phase, $s->transferIndex, false, 'in_progress', $strategy ?? $s->strategyLevel, false, $failure);
    }
}
