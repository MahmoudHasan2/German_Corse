<?php

declare(strict_types=1);

namespace GermanCoach\Ai\Fake;

use GermanCoach\Ai\EvaluationRequest;
use GermanCoach\Ai\EvaluationResult;
use GermanCoach\Ai\ProviderException;
use GermanCoach\Ai\ProviderUsage;
use GermanCoach\Ai\TutorEvaluator;
use GermanCoach\Ai\TutorPrompt;
use GermanCoach\Evaluation\AnswerNormalizer;

/**
 * Deterministic stand-in for the AI tutor. Used by the automated tests and by the
 * offline demo mode (AI_PROVIDER=fake), where the UI labels it as a demo evaluator.
 *
 * Tests can queue exact raw responses (arrays, raw strings, or exceptions) to drive
 * specific scenarios; otherwise a simple heuristic compares the answer "skeleton"
 * (capitalized words and numbers replaced) with the reference answers.
 */
final class FakeTutor implements TutorEvaluator
{
    /** @var list<mixed> */
    private static array $queue = [];
    public static int $calls = 0;
    /** @var list<EvaluationRequest> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$calls = 0;
        self::$requests = [];
    }

    /** @param array<string,mixed>|string|ProviderException $response */
    public static function queue(array|string|ProviderException $response): void
    {
        self::$queue[] = $response;
    }

    public function providerName(): string
    {
        return 'fake';
    }

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        self::$calls++;
        self::$requests[] = $request;
        $usage = new ProviderUsage(
            inputTokens: (int) ceil(mb_strlen(TutorPrompt::instructions() . TutorPrompt::input($request)) / 4),
            outputTokens: 180,
            cachedTokens: 0,
            reasoningTokens: 0,
            reportedByProvider: true,
        );
        if (self::$queue !== []) {
            $next = array_shift(self::$queue);
            if ($next instanceof ProviderException) {
                throw $next;
            }
            return new EvaluationResult(is_string($next) ? (json_decode($next, true) ?? $next) : $next, $usage, 'fake-tutor', 'fake-' . self::$calls);
        }
        return new EvaluationResult($this->heuristic($request), $usage, 'fake-tutor', 'fake-' . self::$calls);
    }

    /** @return array<string,mixed> */
    private function heuristic(EvaluationRequest $r): array
    {
        $best = '';
        $bestScore = 0.0;
        foreach ($r->item->referenceAnswers as $ref) {
            $score = AnswerNormalizer::similarity(self::skeleton($r->answer), self::skeleton($ref));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$ref, $score];
            }
        }
        $base = [
            'understood' => true, 'intent_match' => true, 'grammar_correct' => true, 'naturalness' => 'natural',
            'possible_transcription_error' => false, 'corrected_answer' => null, 'errors' => [], 'hint_de' => null,
            'confidence' => 0.85,
        ];
        if ($bestScore >= 0.97) {
            return ['verdict' => 'correct_variant', 'explanation_ar' => '(تقييم تجريبي) إجابة صحيحة بصياغة مختلفة.'] + $base;
        }
        if ($bestScore >= 0.5) {
            return [
                'verdict' => 'grammar_errors',
                'grammar_correct' => false,
                'naturalness' => 'needs_improvement',
                'corrected_answer' => $best,
                'errors' => [[
                    'category' => $r->item->grammarFocus ?? 'other',
                    'original' => mb_substr($r->answer, 0, 200),
                    'correction' => $best,
                    'explanation_ar' => '(تقييم تجريبي) قارن جملتك بالجملة المصحّحة.',
                ]],
                'hint_de' => 'Vergleichen Sie Ihre Antwort mit der Aufgabe.',
                'explanation_ar' => '(تقييم تجريبي) الفكرة قريبة، لكن هناك خطأ لغوي.',
            ] + $base;
        }
        return [
            'verdict' => 'off_topic', 'understood' => false, 'intent_match' => false, 'grammar_correct' => false,
            'naturalness' => 'needs_improvement', 'hint_de' => 'Lesen Sie die Aufgabe noch einmal.',
            'explanation_ar' => '(تقييم تجريبي) الإجابة لا تجيب عن السؤال.', 'confidence' => 0.8,
        ] + $base;
    }

    /** Replaces capitalized words (except the first) and numbers with X, so personal details do not matter. */
    private static function skeleton(string $text): string
    {
        $words = preg_split('/\s+/u', trim(AnswerNormalizer::strict($text))) ?: [];
        foreach ($words as $i => $w) {
            if ($i > 0 && (preg_match('/^\p{Lu}/u', $w) || preg_match('/^\d/', $w))) {
                $words[$i] = 'X';
            }
        }
        return implode(' ', $words);
    }
}
