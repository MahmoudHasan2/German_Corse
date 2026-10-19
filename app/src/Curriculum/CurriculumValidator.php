<?php

declare(strict_types=1);

namespace GermanCoach\Curriculum;

use GermanCoach\Evaluation\ErrorCategories;

/** Structural validation of curriculum JSON with readable error messages for content editors. */
final class CurriculumValidator
{
    /** @param list<array<string,mixed>> $lessons @return list<string> */
    public function validate(array $lessons): array
    {
        $errors = [];
        $lessonSlugs = [];
        $exerciseSlugs = [];
        foreach ($lessons as $i => $l) {
            $where = ($l['_file'] ?? '?') . ' lesson ' . (is_string($l['slug'] ?? null) ? $l['slug'] : "#$i");
            foreach (['slug', 'level', 'category', 'title_de', 'title_ar', 'objective_ar'] as $f) {
                if (!is_string($l[$f] ?? null) || trim($l[$f]) === '') {
                    $errors[] = "$where: missing \"$f\"";
                }
            }
            $slug = (string) ($l['slug'] ?? '');
            if ($slug !== '' && !preg_match('/^[a-z0-9][a-z0-9\-]{1,98}$/', $slug)) {
                $errors[] = "$where: slug must be lowercase letters, digits and dashes";
            }
            if (isset($lessonSlugs[$slug])) {
                $errors[] = "$where: duplicate lesson slug";
            }
            $lessonSlugs[$slug] = true;
            if (!in_array($l['level'] ?? null, CurriculumImporter::LEVELS, true)) {
                $errors[] = "$where: level must be A1, A2 or B1";
            }
            if (isset($l['kind']) && !in_array($l['kind'], ['lesson', 'assessment'], true)) {
                $errors[] = "$where: kind must be lesson or assessment";
            }
            if (!is_array($l['skills'] ?? null) || array_diff($l['skills'], CurriculumImporter::SKILL_AREAS) !== []) {
                $errors[] = "$where: skills must be a list of " . implode('/', CurriculumImporter::SKILL_AREAS);
            }
            if (!is_array($l['exercises'] ?? null) || $l['exercises'] === []) {
                $errors[] = "$where: needs at least one exercise";
                continue;
            }
            foreach ($l['dialogue'] ?? [] as $d => $line) {
                if (!is_string($line['de'] ?? null) || !is_string($line['ar'] ?? null)) {
                    $errors[] = "$where: dialogue line #$d needs \"de\" and \"ar\"";
                }
            }
            foreach ($l['vocabulary'] ?? [] as $v => $word) {
                if (!is_string($word['de'] ?? null) || !is_string($word['ar'] ?? null)) {
                    $errors[] = "$where: vocabulary #$v needs \"de\" and \"ar\"";
                }
                if (isset($word['article']) && !in_array($word['article'], ['der', 'die', 'das'], true)) {
                    $errors[] = "$where: vocabulary #$v article must be der/die/das";
                }
            }
            foreach ($l['exercises'] as $j => $e) {
                $ew = "$where exercise " . (is_string($e['slug'] ?? null) ? $e['slug'] : "#$j");
                foreach (['slug', 'type', 'skill_area', 'instruction_ar', 'prompt_de'] as $f) {
                    if (!is_string($e[$f] ?? null) || trim($e[$f]) === '') {
                        $errors[] = "$ew: missing \"$f\"";
                    }
                }
                $es = (string) ($e['slug'] ?? '');
                if (isset($exerciseSlugs[$es])) {
                    $errors[] = "$ew: duplicate exercise slug";
                }
                $exerciseSlugs[$es] = true;
                if (!in_array($e['type'] ?? null, CurriculumImporter::EXERCISE_TYPES, true)) {
                    $errors[] = "$ew: unknown type";
                }
                if (!in_array($e['skill_area'] ?? null, CurriculumImporter::SKILL_AREAS, true)) {
                    $errors[] = "$ew: unknown skill_area";
                }
                if (isset($e['context_mode']) && !in_array($e['context_mode'], CurriculumImporter::CONTEXT_MODES, true)) {
                    $errors[] = "$ew: unknown context_mode";
                }
                if (($e['context_mode'] ?? 'none') !== 'none' && !is_string($e['context_de'] ?? null)) {
                    $errors[] = "$ew: context_mode requires context_de";
                }
                if (isset($e['grammar_focus']) && !in_array($e['grammar_focus'], ErrorCategories::ALL, true)) {
                    $errors[] = "$ew: grammar_focus must be one of the error categories";
                }
                if (!self::stringList($e['reference_answers'] ?? null)) {
                    $errors[] = "$ew: reference_answers must be a non-empty list of strings";
                }
                foreach ($e['transfer_tasks'] ?? [] as $t => $task) {
                    if (!is_string($task['prompt_de'] ?? null) || !self::stringList($task['reference_answers'] ?? null)) {
                        $errors[] = "$ew: transfer task #$t needs prompt_de and reference_answers";
                    }
                }
                if (isset($e['diagnostic_level']) && !in_array($e['diagnostic_level'], CurriculumImporter::LEVELS, true)) {
                    $errors[] = "$ew: diagnostic_level must be A1, A2 or B1";
                }
            }
        }
        foreach ($lessons as $l) {
            foreach ($l['prerequisites'] ?? [] as $req) {
                if (!is_string($req) || !isset($lessonSlugs[$req])) {
                    $errors[] = 'lesson ' . ($l['slug'] ?? '?') . ': unknown prerequisite "' . (is_string($req) ? $req : '?') . '"';
                } elseif ($req === ($l['slug'] ?? null)) {
                    $errors[] = 'lesson ' . $req . ': cannot require itself';
                }
            }
        }
        return $errors;
    }

    private static function stringList(mixed $v): bool
    {
        if (!is_array($v) || $v === []) {
            return false;
        }
        foreach ($v as $s) {
            if (!is_string($s) || trim($s) === '') {
                return false;
            }
        }
        return true;
    }
}
