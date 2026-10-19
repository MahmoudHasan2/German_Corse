<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/** Text normalization and similarity for rule-based checks (exact match, copy detection). */
final class AnswerNormalizer
{
    /** Lower-cases, unifies quotes/dashes, strips punctuation and collapses whitespace. */
    public static function loose(string $text): string
    {
        return mb_strtolower(self::stripPunctuation($text));
    }

    /**
     * Keeps capitalization (German nouns!) except for the first letter, which may
     * legitimately differ when a learner answers with a fragment.
     */
    public static function strict(string $text): string
    {
        $s = self::stripPunctuation($text);
        if ($s === '') {
            return '';
        }
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    public static function wordCount(string $text): int
    {
        $s = self::stripPunctuation($text);
        return $s === '' ? 0 : count(preg_split('/\s+/u', $s) ?: []);
    }

    /** Similarity 0..1 based on character Levenshtein distance of the loose forms. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::loose($a);
        $b = self::loose($b);
        if ($a === '' && $b === '') {
            return 1.0;
        }
        $ca = mb_str_split(mb_substr($a, 0, 600));
        $cb = mb_str_split(mb_substr($b, 0, 600));
        $la = count($ca);
        $lb = count($cb);
        if ($la === 0 || $lb === 0) {
            return 0.0;
        }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $ca[$i - 1] === $cb[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return 1.0 - $prev[$lb] / max($la, $lb);
    }

    private static function stripPunctuation(string $text): string
    {
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '„' => '"', '”' => '"', '«' => '"', '»' => '"', '–' => '-', '—' => '-']);
        // Remove sentence punctuation but keep digits, letters, apostrophes inside words and decimal commas (7,50).
        $text = preg_replace('/(?<!\d)[,](?!\d)|[.!?;:"()\[\]…](?!\d)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
