<?php

declare(strict_types=1);

namespace GermanCoach\Progress;

use DateTimeImmutable;
use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;
use GermanCoach\Curriculum\CurriculumImporter;
use GermanCoach\Curriculum\LessonService;
use GermanCoach\Evaluation\ErrorCategories;
use GermanCoach\Learning\AssessmentService;
use GermanCoach\Review\ReviewService;
use GermanCoach\Usage\UsageGuard;

/**
 * Statistics for the dashboard and progress page. Every number is computed from
 * stored records (attempts, decisions, activity); nothing is estimated or invented.
 */
final class ProgressService
{
    public const STUDY_TARGET_DAYS = 90;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly LessonService $lessons,
        private readonly ReviewService $reviews,
        private readonly AssessmentService $assessments,
        private readonly UsageGuard $guard,
    ) {
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function dashboard(array $user): array
    {
        $userId = (int) $user['id'];
        $todaySeconds = (int) $this->db->scalar('SELECT active_seconds FROM daily_activity WHERE user_id = ? AND activity_date = ?', [$userId, $this->today()]);
        $goal = (int) $user['daily_goal_minutes'];
        $dueExercises = $this->reviews->dueCount($userId, 'exercise');
        $dueVocab = $this->reviews->dueCount($userId, 'vocabulary');
        $lessons = $this->lessons->lessonsForUser($userId, $user['start_level'] ?? null);
        $active = $this->db->all(
            "SELECT s.id, s.mode, s.lesson_id, l.title_ar, l.title_de FROM learning_sessions s LEFT JOIN lessons l ON l.id = s.lesson_id
             WHERE s.user_id = ? AND s.state <> 'completed' ORDER BY s.last_activity_at DESC LIMIT 3",
            [$userId]
        );
        $hasAttempts = (int) $this->db->scalar('SELECT COUNT(*) FROM answer_attempts WHERE user_id = ?', [$userId]) > 0;
        $hasDiagnostic = (int) $this->db->scalar("SELECT COUNT(*) FROM assessments WHERE user_id = ? AND kind = 'diagnostic' AND status = 'completed'", [$userId]) > 0;
        $lastWeekly = $this->db->scalar("SELECT MAX(completed_at) FROM assessments WHERE user_id = ? AND kind = 'weekly' AND status = 'completed'", [$userId]);
        $usage = $this->guard->snapshot($userId);

        $nextLesson = null;
        foreach ($lessons as $l) {
            if (in_array($l['status'], ['in_progress', 'available'], true)) {
                $nextLesson = $l;
                break;
            }
        }

        $suggestions = [];
        if (!$hasDiagnostic && !$hasAttempts) {
            $suggestions[] = ['type' => 'diagnostic', 'title_ar' => 'ابدأ باختبار تحديد المستوى (15 دقيقة تقريبًا)', 'reason_ar' => 'حتى نقترح نقطة البداية المناسبة لك.'];
        }
        if ($dueExercises > 0) {
            $suggestions[] = ['type' => 'review', 'title_ar' => "راجع $dueExercises تمارين مستحقة", 'reason_ar' => 'المراجعة في الوقت المناسب تثبّت ما تعلّمته.'];
        }
        foreach ($active as $a) {
            if ($a['mode'] === 'lesson') {
                $suggestions[] = ['type' => 'resume', 'session_id' => (int) $a['id'], 'title_ar' => 'تابع درس: ' . $a['title_ar'], 'reason_ar' => 'لديك جلسة غير مكتملة.'];
                break;
            }
        }
        if ($nextLesson !== null) {
            $suggestions[] = ['type' => 'lesson', 'lesson_id' => $nextLesson['id'], 'title_ar' => 'الدرس التالي: ' . $nextLesson['title_ar'], 'reason_ar' => 'التالي في منهجك حسب المتطلبات السابقة.'];
        }
        if ($dueVocab > 0) {
            $suggestions[] = ['type' => 'vocabulary', 'title_ar' => "راجع $dueVocab بطاقات مفردات", 'reason_ar' => 'مراجعة سريعة بدون ذكاء اصطناعي.'];
        }
        if ($hasAttempts && ($lastWeekly === null || Clock::fromSql((string) $lastWeekly) < $this->clock->now()->modify('-7 days'))) {
            $suggestions[] = ['type' => 'weekly', 'title_ar' => 'التقييم الأسبوعي القصير', 'reason_ar' => 'لمتابعة تقدّمك (تقدير داخلي غير رسمي).'];
        }

        return [
            'user' => ['display_name' => (string) $user['display_name'], 'start_level' => $user['start_level'] ?? null, 'target_level' => (string) $user['target_level']],
            'today' => [
                'active_minutes' => intdiv($todaySeconds, 60),
                'goal_minutes' => $goal,
                'streak_days' => $this->streak($userId),
            ],
            'plan' => $this->dailyPlan($goal, $dueExercises, $dueVocab, $nextLesson),
            'reviews_due' => ['exercises' => $dueExercises, 'vocabulary' => $dueVocab],
            'lessons' => [
                'completed' => count(array_filter($lessons, static fn ($l) => $l['status'] === 'completed')),
                'total' => count($lessons),
                'next' => $nextLesson,
            ],
            'active_sessions' => array_map(static fn ($a) => ['id' => (int) $a['id'], 'mode' => (string) $a['mode'], 'title_ar' => $a['title_ar']], $active),
            'suggestions' => array_slice($suggestions, 0, 4),
            'study_target' => $this->studyTarget($user),
            'usage' => ['warning' => $usage['warning'], 'ai_enabled' => $usage['ai_enabled'], 'provider_configured' => $usage['provider_configured'], 'demo_provider' => $usage['provider'] === 'fake'],
        ];
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function progress(array $user): array
    {
        $userId = (int) $user['id'];
        $since = Clock::toSql($this->clock->now()->modify('-30 days'));

        $skills = [];
        foreach (CurriculumImporter::SKILL_AREAS as $area) {
            $skills[$area] = ['exercises_total' => 0, 'exercises_mastered' => 0, 'first_attempts' => 0, 'first_attempt_successes' => 0];
        }
        foreach ($this->db->all(
            "SELECT e.skill_area, COUNT(*) AS total, SUM(up.status = 'provisionally_mastered') AS mastered
             FROM exercises e JOIN lessons l ON l.id = e.lesson_id AND l.kind = 'lesson' AND l.is_active = 1
             LEFT JOIN user_progress up ON up.exercise_id = e.id AND up.user_id = ?
             WHERE e.is_active = 1 GROUP BY e.skill_area",
            [$userId]
        ) as $r) {
            $skills[$r['skill_area']]['exercises_total'] = (int) $r['total'];
            $skills[$r['skill_area']]['exercises_mastered'] = (int) $r['mastered'];
        }
        foreach ($this->db->all(
            "SELECT e.skill_area, COUNT(*) AS n, SUM(a.outcome = 'success') AS ok
             FROM answer_attempts a JOIN exercises e ON e.id = a.exercise_id
             WHERE a.user_id = ? AND a.attempt_number = 1 AND a.outcome IN ('success','failure') AND a.created_at >= ?
             GROUP BY e.skill_area",
            [$userId, $since]
        ) as $r) {
            $skills[$r['skill_area']]['first_attempts'] = (int) $r['n'];
            $skills[$r['skill_area']]['first_attempt_successes'] = (int) $r['ok'];
        }
        // Speaking also includes every answer given by voice, whatever the exercise area.
        $voice = $this->db->one(
            "SELECT COUNT(*) AS n, SUM(outcome = 'success') AS ok FROM answer_attempts
             WHERE user_id = ? AND input_mode = 'audio' AND outcome IN ('success','failure') AND created_at >= ?",
            [$userId, $since]
        ) ?? [];
        $skills['speaking']['voice_answers'] = (int) ($voice['n'] ?? 0);
        $skills['speaking']['voice_successes'] = (int) ($voice['ok'] ?? 0);
        foreach ($skills as $k => $s) {
            $skills[$k]['first_attempt_accuracy'] = $s['first_attempts'] > 0 ? round($s['first_attempt_successes'] / $s['first_attempts'], 3) : null;
        }

        $daily = $this->db->all(
            'SELECT activity_date, active_seconds, attempts, successes, audio_seconds, reviews FROM daily_activity
             WHERE user_id = ? AND activity_date >= ? ORDER BY activity_date',
            [$userId, $this->clock->now()->modify('-13 days')->format('Y-m-d')]
        );

        $weak = $this->db->all(
            'SELECT category, occurrences, last_original, last_correction, last_explanation_ar, last_seen_at FROM error_patterns
             WHERE user_id = ? ORDER BY occurrences DESC, last_seen_at DESC LIMIT 6',
            [$userId]
        );
        [$in, $vals] = Database::inList(ErrorCategories::VOCABULARY);
        $vocabMistakes = $this->db->all(
            "SELECT category, original_text, correction, explanation_ar, created_at FROM attempt_errors
             WHERE user_id = ? AND category IN ($in) ORDER BY id DESC LIMIT 10",
            array_merge([$userId], $vals)
        );
        $recent = fn (string $outcome) => array_map(static fn ($r) => [
            'prompt_de' => (string) $r['prompt_de'],
            'answer_text' => (string) $r['answer_text'],
            'verdict' => (string) $r['verdict'],
            'input_mode' => (string) $r['input_mode'],
            'created_at' => (string) $r['created_at'],
        ], $this->db->all(
            'SELECT e.prompt_de, a.answer_text, a.verdict, a.input_mode, a.created_at FROM answer_attempts a JOIN exercises e ON e.id = a.exercise_id
             WHERE a.user_id = ? AND a.outcome = ? ORDER BY a.id DESC LIMIT 8',
            [$userId, $outcome]
        ));
        $lessons = $this->lessons->lessonsForUser($userId, $user['start_level'] ?? null);

        return [
            'skills' => $skills,
            'daily' => array_map(static fn ($d) => [
                'date' => (string) $d['activity_date'],
                'active_minutes' => intdiv((int) $d['active_seconds'], 60),
                'attempts' => (int) $d['attempts'],
                'successes' => (int) $d['successes'],
                'audio_minutes' => round(((int) $d['audio_seconds']) / 60, 1),
                'reviews' => (int) $d['reviews'],
            ], $daily),
            'lessons' => [
                'completed' => count(array_filter($lessons, static fn ($l) => $l['status'] === 'completed')),
                'total' => count($lessons),
                'by_level' => self::byLevel($lessons),
            ],
            'weakest_categories' => array_map(static fn ($w) => [
                'category' => (string) $w['category'],
                'label_ar' => ErrorCategories::LABELS_AR[$w['category']] ?? (string) $w['category'],
                'occurrences' => (int) $w['occurrences'],
                'example_original' => $w['last_original'],
                'example_correction' => $w['last_correction'],
                'explanation_ar' => $w['last_explanation_ar'],
                'last_seen_at' => (string) $w['last_seen_at'],
            ], $weak),
            'vocabulary_mistakes' => array_map(static fn ($v) => [
                'label_ar' => ErrorCategories::LABELS_AR[$v['category']] ?? (string) $v['category'],
                'original' => (string) $v['original_text'],
                'correction' => (string) $v['correction'],
                'explanation_ar' => (string) $v['explanation_ar'],
                'created_at' => (string) $v['created_at'],
            ], $vocabMistakes),
            'recent_successes' => $recent('success'),
            'recent_failures' => $recent('failure'),
            'assessments' => $this->assessments->history($userId, 12),
            'reviews' => [
                'due_exercises' => $this->reviews->dueCount($userId, 'exercise'),
                'due_vocabulary' => $this->reviews->dueCount($userId, 'vocabulary'),
                'scheduled_total' => (int) $this->db->scalar('SELECT COUNT(*) FROM review_schedule WHERE user_id = ?', [$userId]),
            ],
            'study_target' => $this->studyTarget($user),
            'totals' => [
                'active_minutes' => intdiv((int) $this->db->scalar('SELECT COALESCE(SUM(active_seconds),0) FROM daily_activity WHERE user_id = ?', [$userId]), 60),
                'attempts' => (int) $this->db->scalar('SELECT COUNT(*) FROM answer_attempts WHERE user_id = ?', [$userId]),
                'mastered' => (int) $this->db->scalar("SELECT COUNT(*) FROM user_progress WHERE user_id = ? AND status = 'provisionally_mastered'", [$userId]),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $lessons @return array<string,array{completed:int,total:int}> */
    private static function byLevel(array $lessons): array
    {
        $out = [];
        foreach ($lessons as $l) {
            $out[$l['level']]['total'] = ($out[$l['level']]['total'] ?? 0) + 1;
            $out[$l['level']]['completed'] = ($out[$l['level']]['completed'] ?? 0) + ($l['status'] === 'completed' ? 1 : 0);
        }
        return $out;
    }

    /** @param array<string,mixed>|null $nextLesson @return list<array<string,mixed>> */
    private function dailyPlan(int $goal, int $dueExercises, int $dueVocab, ?array $nextLesson): array
    {
        $reviewMin = $dueExercises > 0 ? min((int) round($goal * 0.25), max(10, $dueExercises * 3)) : 0;
        $vocabMin = (int) round($goal * 0.1);
        $speakMin = (int) round($goal * 0.15);
        $lessonMin = max(0, $goal - $reviewMin - $vocabMin - $speakMin);
        $plan = [];
        if ($reviewMin > 0) {
            $plan[] = ['block' => 'review', 'minutes' => $reviewMin, 'title_ar' => 'مراجعة التمارين المستحقة'];
        }
        $plan[] = ['block' => 'lesson', 'minutes' => $lessonMin, 'title_ar' => $nextLesson !== null ? 'درس: ' . $nextLesson['title_ar'] : 'متابعة المنهج', 'lesson_id' => $nextLesson['id'] ?? null];
        $plan[] = ['block' => 'speaking', 'minutes' => $speakMin, 'title_ar' => 'إجابات صوتية (تكلّم بدل الكتابة)'];
        $plan[] = ['block' => 'vocabulary', 'minutes' => $vocabMin, 'title_ar' => $dueVocab > 0 ? "بطاقات المفردات ($dueVocab مستحقة)" : 'بطاقات المفردات'];
        return $plan;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function studyTarget(array $user): array
    {
        $start = $user['study_start_date'] !== null ? new DateTimeImmutable((string) $user['study_start_date']) : null;
        $day = $start !== null ? max(1, (int) $start->diff(new DateTimeImmutable($this->today()))->days + 1) : 1;
        return [
            'target_days' => self::STUDY_TARGET_DAYS,
            'day' => $day,
            'daily_goal_minutes' => (int) $user['daily_goal_minutes'],
            'note_ar' => 'الهدف (90 يومًا × ساعتان يوميًا) خطة دراسة وليس وعدًا بالوصول إلى مستوى B1. التقدّم الحقيقي يُقاس بما تتقنه فعليًا.',
        ];
    }

    private function streak(int $userId): int
    {
        $dates = array_column($this->db->all(
            'SELECT activity_date FROM daily_activity WHERE user_id = ? AND active_seconds > 0 ORDER BY activity_date DESC LIMIT 120',
            [$userId]
        ), 'activity_date');
        $streak = 0;
        $cursor = new DateTimeImmutable($this->today());
        if ($dates !== [] && (string) $dates[0] !== $cursor->format('Y-m-d')) {
            $cursor = $cursor->modify('-1 day'); // today not started yet — streak still counts until yesterday
        }
        foreach ($dates as $d) {
            if ((string) $d === $cursor->format('Y-m-d')) {
                $streak++;
                $cursor = $cursor->modify('-1 day');
            } else {
                break;
            }
        }
        return $streak;
    }

    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }
}
