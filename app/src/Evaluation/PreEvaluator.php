<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/**
 * Deterministic checks that run before (and instead of) any paid AI call:
 * - an empty or clearly too short answer is "incomplete";
 * - an answer that matches a curated reference answer is "correct".
 * Returns null when the answer needs real evaluation.
 */
final class PreEvaluator
{
    public function evaluate(ExerciseItem $item, string $answer): ?Evaluation
    {
        $words = AnswerNormalizer::wordCount($answer);
        if ($words === 0) {
            return Evaluation::rule('incomplete', null, 'لم نستلم إجابة. اكتب أو سجّل إجابتك بالألمانية.');
        }

        $strict = AnswerNormalizer::strict($answer);
        foreach ($item->referenceAnswers as $ref) {
            if ($strict === AnswerNormalizer::strict($ref)) {
                return Evaluation::rule('correct', $ref, 'إجابة صحيحة تمامًا.');
            }
        }

        if ($words < $item->minWords) {
            return Evaluation::rule(
                'incomplete',
                null,
                'الإجابة قصيرة جدًا لهذه المهمة. اكتب جملة كاملة (على الأقل ' . $item->minWords . ' كلمات).'
            );
        }
        return null;
    }
}
