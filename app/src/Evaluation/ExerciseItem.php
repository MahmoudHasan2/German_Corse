<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/** The concrete item the learner must answer now: the main exercise or one of its transfer tasks. */
final class ExerciseItem
{
    /**
     * @param list<string> $referenceAnswers
     * @param list<string> $keyPoints
     */
    public function __construct(
        public readonly int $exerciseId,
        public readonly string $phase,          // main | transfer
        public readonly int $transferIndex,
        public readonly string $type,
        public readonly string $skillArea,
        public readonly ?string $grammarFocus,
        public readonly string $instructionAr,
        public readonly string $promptDe,
        public readonly ?string $contextDe,
        public readonly string $contextMode,
        public readonly array $referenceAnswers,
        public readonly array $keyPoints,
        public readonly int $minWords,
        public readonly string $level,
    ) {
    }

    /** @param array<string,mixed> $exercise row joined with lessons.level AS lesson_level */
    public static function fromExercise(array $exercise, string $phase, int $transferIndex): self
    {
        $refs = json_decode((string) $exercise['reference_answers'], true) ?: [];
        $keys = json_decode((string) ($exercise['key_points'] ?? 'null'), true) ?: [];
        $instruction = (string) $exercise['instruction_ar'];
        $prompt = (string) $exercise['prompt_de'];
        $context = $exercise['context_de'] !== null ? (string) $exercise['context_de'] : null;
        $mode = (string) $exercise['context_mode'];
        $minWords = (int) $exercise['min_words'];

        if ($phase === 'transfer') {
            $tasks = self::transferTasks($exercise);
            $task = $tasks[$transferIndex] ?? null;
            if ($task === null) {
                throw new \OutOfRangeException('Transfer task does not exist.');
            }
            $refs = $task['reference_answers'];
            $keys = $task['key_points'] ?? $keys;
            $instruction = (string) ($task['instruction_ar'] ?? $instruction);
            $prompt = (string) $task['prompt_de'];
            $context = null;
            $mode = 'none';
            $minWords = (int) ($task['min_words'] ?? max(1, (int) floor($minWords * 0.8)));
        }

        return new self(
            (int) $exercise['id'],
            $phase,
            $transferIndex,
            (string) $exercise['type'],
            (string) $exercise['skill_area'],
            $exercise['grammar_focus'] !== null ? (string) $exercise['grammar_focus'] : null,
            $instruction,
            $prompt,
            $context,
            $mode,
            array_values(array_map('strval', $refs)),
            array_values(array_map('strval', $keys)),
            max(1, $minWords),
            (string) ($exercise['lesson_level'] ?? 'A1'),
        );
    }

    /** @param array<string,mixed> $exercise @return list<array<string,mixed>> */
    public static function transferTasks(array $exercise): array
    {
        $tasks = json_decode((string) ($exercise['transfer_tasks'] ?? 'null'), true);
        return is_array($tasks) ? array_values($tasks) : [];
    }
}
