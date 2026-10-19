<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/**
 * The strict JSON schema the tutor model must return. It is sent to the provider
 * (OpenAI structured outputs, strict mode) AND re-validated on the server, because
 * a provider guarantee is not a substitute for our own validation.
 */
final class EvaluationSchema
{
    public const VERDICTS = [
        'correct',                       // correct and natural
        'correct_variant',               // correct, differs from the reference wording
        'minor_errors',                  // understandable, small mistakes
        'grammar_errors',                // clear grammatical mistakes
        'off_topic',                     // does not answer the question
        'incomplete',                    // too short / incomplete to evaluate
        'possible_transcription_error',  // the text looks like a speech-recognition problem
        'uncertain',                     // evaluator cannot decide reliably
    ];

    public const NATURALNESS = ['natural', 'acceptable', 'needs_improvement', 'unnatural'];

    public const MAX_ERRORS = 6;

    /**
     * Server-side schema (with generous hard length limits; display limits are applied by truncation).
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        $nullableString = static fn (int $max) => ['type' => ['string', 'null'], 'maxLength' => $max];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'verdict', 'understood', 'intent_match', 'grammar_correct', 'naturalness',
                'possible_transcription_error', 'corrected_answer', 'errors', 'hint_de', 'explanation_ar', 'confidence',
            ],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => self::VERDICTS],
                'understood' => ['type' => 'boolean'],
                'intent_match' => ['type' => 'boolean'],
                'grammar_correct' => ['type' => 'boolean'],
                'naturalness' => ['type' => 'string', 'enum' => self::NATURALNESS],
                'possible_transcription_error' => ['type' => 'boolean'],
                'corrected_answer' => $nullableString(2000),
                'errors' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_ERRORS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['category', 'original', 'correction', 'explanation_ar'],
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => ErrorCategories::ALL],
                            'original' => ['type' => 'string', 'maxLength' => 1000],
                            'correction' => ['type' => 'string', 'maxLength' => 1000],
                            'explanation_ar' => ['type' => 'string', 'maxLength' => 1500],
                        ],
                    ],
                ],
                'hint_de' => $nullableString(1000),
                'explanation_ar' => ['type' => 'string', 'maxLength' => 3000],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    /**
     * Schema sent to OpenAI Structured Outputs (strict). Strict mode does not support
     * minLength/maxLength for strings, so those keywords are removed recursively.
     * @return array<string,mixed>
     */
    public static function providerSchema(): array
    {
        return self::stripKeywords(self::schema(), ['maxLength', 'minLength']);
    }

    /** @param array<string,mixed> $schema @param list<string> $keywords @return array<string,mixed> */
    private static function stripKeywords(array $schema, array $keywords): array
    {
        foreach ($keywords as $k) {
            unset($schema[$k]);
        }
        foreach ($schema as $key => $value) {
            if (is_array($value) && $key !== 'enum' && $key !== 'required' && $key !== 'type') {
                $schema[$key] = self::stripKeywords($value, $keywords);
            }
        }
        return $schema;
    }
}
