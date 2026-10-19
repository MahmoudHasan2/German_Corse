<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/**
 * A validated, sanitized evaluation of one learner answer.
 * All text fields are plain text (rendered with textContent in the browser).
 */
final class Evaluation
{
    /**
     * @param list<array{category:string,original:string,correction:string,explanation_ar:string}> $errors
     */
    public function __construct(
        public readonly string $verdict,
        public readonly bool $understood,
        public readonly bool $intentMatch,
        public readonly bool $grammarCorrect,
        public readonly string $naturalness,
        public readonly bool $possibleTranscriptionError,
        public readonly ?string $correctedAnswer,
        public readonly array $errors,
        public readonly ?string $hintDe,
        public readonly string $explanationAr,
        public readonly float $confidence,
        public readonly string $evaluator, // ai | rule | offline
    ) {
    }

    /**
     * Builds an Evaluation from untrusted decoded JSON.
     * @throws InvalidEvaluationException
     */
    public static function fromArray(mixed $data, string $evaluator = 'ai'): self
    {
        $errors = JsonSchemaValidator::validate($data, EvaluationSchema::schema());
        if ($errors !== []) {
            throw new InvalidEvaluationException('Evaluation failed schema validation: ' . implode('; ', array_slice($errors, 0, 5)));
        }
        /** @var array<string,mixed> $data */
        $cleanErrors = [];
        foreach ($data['errors'] as $e) {
            $cleanErrors[] = [
                'category' => (string) $e['category'],
                'original' => self::clean((string) $e['original'], 200),
                'correction' => self::clean((string) $e['correction'], 200),
                'explanation_ar' => self::clean((string) $e['explanation_ar'], 400),
            ];
        }
        return new self(
            (string) $data['verdict'],
            (bool) $data['understood'],
            (bool) $data['intent_match'],
            (bool) $data['grammar_correct'],
            (string) $data['naturalness'],
            (bool) $data['possible_transcription_error'],
            $data['corrected_answer'] !== null ? self::clean((string) $data['corrected_answer'], 600) : null,
            $cleanErrors,
            $data['hint_de'] !== null ? self::clean((string) $data['hint_de'], 300) : null,
            self::clean((string) $data['explanation_ar'], 700),
            max(0.0, min(1.0, (float) $data['confidence'])),
            $evaluator,
        );
    }

    /** Decodes a provider JSON string, rejecting anything that is not a single JSON object. */
    public static function fromJson(string $json, string $evaluator = 'ai'): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidEvaluationException('Evaluation is not valid JSON.');
        }
        return self::fromArray($data, $evaluator);
    }

    public static function rule(string $verdict, ?string $correctedAnswer, string $explanationAr): self
    {
        $ok = in_array($verdict, ['correct', 'correct_variant'], true);
        return new self($verdict, $ok, $ok, $ok, $ok ? 'natural' : 'needs_improvement', false, $correctedAnswer, [], null, $explanationAr, 1.0, 'rule');
    }

    public static function offline(string $explanationAr): self
    {
        return new self('uncertain', false, false, false, 'acceptable', false, null, [], null, $explanationAr, 0.0, 'offline');
    }

    /** @return list<string> */
    public function errorCategories(): array
    {
        return array_values(array_unique(array_map(static fn ($e) => $e['category'], $this->errors)));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'understood' => $this->understood,
            'intent_match' => $this->intentMatch,
            'grammar_correct' => $this->grammarCorrect,
            'naturalness' => $this->naturalness,
            'possible_transcription_error' => $this->possibleTranscriptionError,
            'corrected_answer' => $this->correctedAnswer,
            'errors' => $this->errors,
            'hint_de' => $this->hintDe,
            'explanation_ar' => $this->explanationAr,
            'confidence' => $this->confidence,
            'evaluator' => $this->evaluator,
        ];
    }

    /** Strips control characters and HTML tags, normalizes whitespace, caps length. */
    private static function clean(string $s, int $max): string
    {
        $s = strip_tags($s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }
}
