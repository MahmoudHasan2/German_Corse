<?php

declare(strict_types=1);

namespace GermanCoach\Curriculum;

use GermanCoach\Core\Database;

/**
 * Imports version-controlled curriculum JSON (curriculum/*.json) into MySQL.
 * Idempotent: rows are upserted by slug, unchanged rows are skipped by content hash,
 * and content missing from the files is deactivated (never deleted), so learner
 * progress is preserved across curriculum updates.
 */
final class CurriculumImporter
{
    public const LEVELS = ['A1', 'A2', 'B1'];
    public const SKILL_AREAS = ['speaking', 'listening', 'grammar', 'vocabulary', 'reading', 'writing'];
    public const EXERCISE_TYPES = ['answer_question', 'translate', 'gap_fill', 'transform', 'build_sentence', 'listening', 'reading', 'writing', 'role_play', 'describe'];
    public const CONTEXT_MODES = ['none', 'read', 'listen'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{lessons:int,exercises:int,vocabulary:int,deactivated:int}
     * @throws CurriculumException on invalid content (nothing is written)
     */
    public function importDirectory(string $dir): array
    {
        $lessons = [];
        $files = glob(rtrim($dir, '/\\') . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['lessons']) || !is_array($data['lessons'])) {
                throw new CurriculumException(basename($file) . ': expected an object with a "lessons" array.');
            }
            foreach ($data['lessons'] as $i => $lesson) {
                if (!is_array($lesson)) {
                    throw new CurriculumException(basename($file) . ": lesson #$i is not an object.");
                }
                $lessons[] = $lesson + ['_file' => basename($file)];
            }
        }
        return $this->import($lessons);
    }

    /**
     * @param list<array<string,mixed>> $lessons
     * @return array{lessons:int,exercises:int,vocabulary:int,deactivated:int}
     */
    public function import(array $lessons): array
    {
        $errors = (new CurriculumValidator())->validate($lessons);
        if ($errors !== []) {
            throw new CurriculumException("Curriculum is invalid:\n- " . implode("\n- ", array_slice($errors, 0, 30)));
        }

        return $this->db->transaction(function (Database $db) use ($lessons): array {
            $stats = ['lessons' => 0, 'exercises' => 0, 'vocabulary' => 0, 'deactivated' => 0];
            $lessonIds = [];
            $exerciseSlugs = [];
            $termKeys = [];

            foreach ($lessons as $order => $l) {
                $lessonId = $this->upsertLesson($l, $order, $stats);
                $lessonIds[(string) $l['slug']] = $lessonId;
                foreach ($l['exercises'] as $eo => $e) {
                    $this->upsertExercise($lessonId, $e, $eo, $stats);
                    $exerciseSlugs[] = (string) $e['slug'];
                }
                foreach ($l['vocabulary'] ?? [] as $v) {
                    $termKeys[] = $this->upsertVocabulary($lessonId, (string) $l['level'], $v, $stats);
                }
            }

            // Prerequisites are rebuilt from the files (by slug) each import.
            $db->execute('DELETE FROM lesson_prerequisites');
            foreach ($lessons as $l) {
                foreach ($l['prerequisites'] ?? [] as $req) {
                    $db->execute(
                        'INSERT INTO lesson_prerequisites (lesson_id, requires_lesson_id) VALUES (?, ?)',
                        [$lessonIds[(string) $l['slug']], $lessonIds[(string) $req]]
                    );
                }
            }

            [$in, $vals] = Database::inList(array_keys($lessonIds));
            $stats['deactivated'] += $db->execute("UPDATE lessons SET is_active = 0 WHERE is_active = 1 AND slug NOT IN ($in)", $vals);
            [$in, $vals] = Database::inList($exerciseSlugs);
            $stats['deactivated'] += $db->execute("UPDATE exercises SET is_active = 0 WHERE is_active = 1 AND slug NOT IN ($in)", $vals);
            [$in, $vals] = Database::inList($termKeys);
            $stats['deactivated'] += $db->execute("UPDATE vocabulary SET is_active = 0 WHERE is_active = 1 AND term_key NOT IN ($in)", $vals);

            return $stats;
        });
    }

    /** @param array<string,mixed> $l @param array<string,int> $stats */
    private function upsertLesson(array $l, int $order, array &$stats): int
    {
        $row = [
            'slug' => (string) $l['slug'],
            'kind' => (string) ($l['kind'] ?? 'lesson'),
            'level' => (string) $l['level'],
            'category' => (string) $l['category'],
            'sort_order' => (int) ($l['sort_order'] ?? ($order + 1) * 10),
            'title_de' => (string) $l['title_de'],
            'title_ar' => (string) $l['title_ar'],
            'objective_ar' => (string) $l['objective_ar'],
            'description_ar' => isset($l['description_ar']) ? (string) $l['description_ar'] : null,
            'skills' => self::json($l['skills']),
            'dialogue' => isset($l['dialogue']) ? self::json($l['dialogue']) : null,
            'grammar_notes' => isset($l['grammar_notes']) ? self::json($l['grammar_notes']) : null,
            'review_targets' => isset($l['review_targets']) ? self::json($l['review_targets']) : null,
            'estimated_minutes' => (int) ($l['estimated_minutes'] ?? 30),
            'is_active' => 1,
        ];
        $row['content_hash'] = hash('sha256', self::json($row));
        $existing = $this->db->one('SELECT id, content_hash, is_active FROM lessons WHERE slug = ?', [$row['slug']]);
        if ($existing === null) {
            $stats['lessons']++;
            return $this->db->insert('lessons', $row);
        }
        if ($existing['content_hash'] !== $row['content_hash'] || (int) $existing['is_active'] !== 1) {
            $this->update('lessons', (int) $existing['id'], $row);
            $stats['lessons']++;
        }
        return (int) $existing['id'];
    }

    /** @param array<string,mixed> $e @param array<string,int> $stats */
    private function upsertExercise(int $lessonId, array $e, int $order, array &$stats): void
    {
        $row = [
            'lesson_id' => $lessonId,
            'slug' => (string) $e['slug'],
            'sort_order' => (int) ($e['sort_order'] ?? ($order + 1) * 10),
            'type' => (string) $e['type'],
            'skill_area' => (string) $e['skill_area'],
            'grammar_focus' => isset($e['grammar_focus']) ? (string) $e['grammar_focus'] : null,
            'instruction_ar' => (string) $e['instruction_ar'],
            'prompt_de' => (string) $e['prompt_de'],
            'context_de' => isset($e['context_de']) ? (string) $e['context_de'] : null,
            'context_mode' => (string) ($e['context_mode'] ?? 'none'),
            'reference_answers' => self::json(array_values($e['reference_answers'])),
            'key_points' => isset($e['key_points']) ? self::json($e['key_points']) : null,
            'hint_de' => isset($e['hint_de']) ? (string) $e['hint_de'] : null,
            'hint_ar' => isset($e['hint_ar']) ? (string) $e['hint_ar'] : null,
            'explanation_ar' => isset($e['explanation_ar']) ? (string) $e['explanation_ar'] : null,
            'transfer_tasks' => isset($e['transfer_tasks']) ? self::json($e['transfer_tasks']) : null,
            'requires_transfer' => (int) (bool) ($e['requires_transfer'] ?? true),
            'difficulty' => (int) ($e['difficulty'] ?? 1),
            'diagnostic_level' => isset($e['diagnostic_level']) ? (string) $e['diagnostic_level'] : null,
            'min_words' => (int) ($e['min_words'] ?? 1),
            'is_active' => 1,
        ];
        $row['content_hash'] = hash('sha256', self::json($row));
        $existing = $this->db->one('SELECT id, content_hash, is_active FROM exercises WHERE slug = ?', [$row['slug']]);
        if ($existing === null) {
            $this->db->insert('exercises', $row);
            $stats['exercises']++;
        } elseif ($existing['content_hash'] !== $row['content_hash'] || (int) $existing['is_active'] !== 1) {
            $this->update('exercises', (int) $existing['id'], $row);
            $stats['exercises']++;
        }
    }

    /** @param array<string,mixed> $v @param array<string,int> $stats */
    private function upsertVocabulary(int $lessonId, string $level, array $v, array &$stats): string
    {
        $article = isset($v['article']) && $v['article'] !== '' ? (string) $v['article'] : null;
        $termKey = mb_strtolower(trim(($article ?? '') . ' ' . (string) $v['de']));
        $row = [
            'lesson_id' => $lessonId,
            'term_key' => $termKey,
            'word_de' => (string) $v['de'],
            'article' => $article,
            'plural' => isset($v['plural']) ? (string) $v['plural'] : null,
            'word_ar' => (string) $v['ar'],
            'example_de' => isset($v['example_de']) ? (string) $v['example_de'] : null,
            'example_ar' => isset($v['example_ar']) ? (string) $v['example_ar'] : null,
            'part_of_speech' => (string) ($v['pos'] ?? ($article !== null ? 'noun' : 'phrase')),
            'level' => $level,
            'is_active' => 1,
        ];
        $existing = $this->db->one('SELECT id FROM vocabulary WHERE term_key = ?', [$termKey]);
        if ($existing === null) {
            $this->db->insert('vocabulary', $row);
            $stats['vocabulary']++;
        } else {
            $this->update('vocabulary', (int) $existing['id'], $row);
        }
        return $termKey;
    }

    /** @param array<string,mixed> $row */
    private function update(string $table, int $id, array $row): void
    {
        $sets = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($row)));
        $this->db->run("UPDATE `$table` SET $sets WHERE id = :id", $row + ['id' => $id]);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
