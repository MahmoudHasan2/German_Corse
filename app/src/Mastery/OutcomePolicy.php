<?php

declare(strict_types=1);

namespace GermanCoach\Mastery;

use GermanCoach\Evaluation\ErrorCategories;
use GermanCoach\Evaluation\Evaluation;

/**
 * Turns an evaluation into an outcome with conservative server-side rules.
 * The model's confidence is NOT treated as a calibrated probability: it can only
 * make the server more cautious, never more generous.
 *
 * Outcomes: success | failure | uncertain | confirm_transcript | unavailable
 */
final class OutcomePolicy
{
    public function __construct(private readonly MasteryPolicy $policy)
    {
    }

    /**
     * @param string $transcriptionConfidence high|medium|low|unknown (only for audio)
     * @return array{outcome:string,reason:string,count_errors:bool}
     */
    public function derive(Evaluation $e, string $inputMode, string $transcriptionConfidence, bool $transcriptEdited): array
    {
        if ($e->evaluator === 'offline') {
            return ['outcome' => 'unavailable', 'reason' => 'ai_unavailable', 'count_errors' => false];
        }

        $verdict = $e->verdict;
        // Consistency: a "correct" verdict that still lists grammar errors is downgraded.
        if (in_array($verdict, ['correct', 'correct_variant'], true)) {
            $substantive = array_diff($e->errorCategories(), ErrorCategories::SURFACE);
            if (!$e->grammarCorrect || $substantive !== []) {
                $verdict = 'minor_errors';
            }
        }

        $isAudio = $inputMode === 'audio' && !$transcriptEdited;
        $sttDoubt = $isAudio && ($transcriptionConfidence === 'low' || $e->possibleTranscriptionError || $verdict === 'possible_transcription_error');

        $positive = in_array($verdict, ['correct', 'correct_variant'], true)
            || ($verdict === 'minor_errors' && $this->policy->minorErrorsPass && $e->understood && $e->intentMatch);

        if ($positive) {
            if ($e->evaluator === 'ai' && $e->confidence < $this->policy->minConfidence) {
                return ['outcome' => 'uncertain', 'reason' => 'low_evaluator_confidence', 'count_errors' => false];
            }
            return ['outcome' => 'success', 'reason' => $verdict, 'count_errors' => $verdict === 'minor_errors'];
        }

        if ($sttDoubt) {
            // Never grade a probable speech-recognition problem as a language mistake.
            return ['outcome' => 'confirm_transcript', 'reason' => 'transcription_uncertain', 'count_errors' => false];
        }
        if ($verdict === 'possible_transcription_error') {
            return ['outcome' => 'uncertain', 'reason' => 'possible_transcription_error_text', 'count_errors' => false];
        }
        if ($verdict === 'uncertain') {
            return ['outcome' => 'uncertain', 'reason' => 'evaluator_uncertain', 'count_errors' => false];
        }
        if ($e->evaluator === 'ai' && $e->confidence < $this->policy->minConfidence * 0.8) {
            return ['outcome' => 'uncertain', 'reason' => 'low_evaluator_confidence', 'count_errors' => false];
        }
        // incomplete / off_topic / grammar_errors / minor_errors (when minor errors do not pass)
        return ['outcome' => 'failure', 'reason' => $verdict, 'count_errors' => $verdict !== 'incomplete'];
    }
}
