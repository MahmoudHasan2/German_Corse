<?php

declare(strict_types=1);

namespace GermanCoach\Http\Controllers;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;
use GermanCoach\Core\HttpException;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Validator;
use GermanCoach\Curriculum\LessonService;
use GermanCoach\Learning\ProgressRecorder;
use GermanCoach\Review\ReviewService;

/**
 * Vocabulary flashcards. Fully usable without any AI request (only optional audio).
 * Cards are drawn from lessons the learner can access.
 */
final class VocabularyController
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly LessonService $lessons,
        private readonly ReviewService $reviews,
        private readonly ProgressRecorder $progress,
    ) {
    }

    /** GET /api/vocabulary?lesson_id= */
    public function index(Request $request): Response
    {
        $user = $request->attribute('user');
        $userId = (int) $user['id'];
        $accessible = array_column(array_filter(
            $this->lessons->lessonsForUser($userId, $user['start_level'] ?? null),
            static fn ($l) => $l['available']
        ), 'id');
        $lessonFilter = isset($request->query['lesson_id']) && ctype_digit($request->query['lesson_id']) ? (int) $request->query['lesson_id'] : null;
        if ($lessonFilter !== null) {
            $accessible = in_array($lessonFilter, $accessible, true) ? [$lessonFilter] : [];
        }
        [$in, $vals] = Database::inList($accessible);
        $rows = $this->db->all(
            "SELECT v.id, v.lesson_id, v.word_de, v.article, v.plural, v.word_ar, v.example_de, v.part_of_speech, v.level,
                    l.title_ar AS lesson_title_ar, r.id AS review_id, r.due_at, r.repetitions, r.lapses, r.interval_days
             FROM vocabulary v JOIN lessons l ON l.id = v.lesson_id
             LEFT JOIN review_schedule r ON r.item_type = 'vocabulary' AND r.item_id = v.id AND r.user_id = ?
             WHERE v.is_active = 1 AND v.lesson_id IN ($in)
             ORDER BY FIELD(v.level, 'A1', 'A2', 'B1'), l.sort_order, v.id",
            array_merge([$userId], $vals)
        );
        $now = $this->clock->now();
        $words = array_map(static function ($r) use ($now) {
            $status = 'new';
            if ($r['review_id'] !== null) {
                $status = Clock::fromSql((string) $r['due_at']) <= $now ? 'due' : ((int) $r['interval_days'] >= 21 ? 'known' : 'learning');
            }
            return [
                'id' => (int) $r['id'],
                'lesson_id' => (int) $r['lesson_id'],
                'lesson_title_ar' => (string) $r['lesson_title_ar'],
                'word_de' => (string) $r['word_de'],
                'article' => $r['article'],
                'plural' => $r['plural'],
                'word_ar' => (string) $r['word_ar'],
                'example_de' => $r['example_de'],
                'part_of_speech' => (string) $r['part_of_speech'],
                'level' => (string) $r['level'],
                'status' => $status,
                'review_id' => $r['review_id'] !== null ? (int) $r['review_id'] : null,
                'due_at' => $r['due_at'],
                'lapses' => (int) ($r['lapses'] ?? 0),
            ];
        }, $rows);
        $counts = array_count_values(array_column($words, 'status'));
        return Response::json(['ok' => true, 'words' => $words, 'counts' => $counts + ['new' => 0, 'due' => 0, 'learning' => 0, 'known' => 0]]);
    }

    /**
     * POST /api/vocabulary/{id}/review {grade: again|hard|good|easy}
     * Grades a card (new or due) and schedules the next review.
     */
    public function review(Request $request): Response
    {
        $user = $request->attribute('user');
        $userId = (int) $user['id'];
        $wordId = $request->routeInt('id');
        $lessonId = $this->db->scalar('SELECT lesson_id FROM vocabulary WHERE id = ? AND is_active = 1', [$wordId]);
        if ($lessonId === null || !$this->lessons->isAvailable($userId, $user['start_level'] ?? null, (int) $lessonId)) {
            throw HttpException::notFound('Word not found.');
        }
        $v = new Validator($request->input());
        $grade = $v->enum('grade', array_keys(ReviewController::CARD_GRADES));
        $elapsedMs = $v->int('elapsed_ms', 0, PHP_INT_MAX, false) ?? 0;
        $v->validate();
        $result = $this->reviews->record($userId, 'vocabulary', $wordId, ReviewController::CARD_GRADES[(string) $grade], 0, 'vocab');
        $this->progress->addDailyActivity($userId, intdiv(min($elapsedMs, 120_000), 1000), 0, 0, 0, 1);
        return Response::json(['ok' => true, 'next' => $result]);
    }
}
