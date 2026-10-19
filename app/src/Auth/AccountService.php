<?php

declare(strict_types=1);

namespace GermanCoach\Auth;

use GermanCoach\Core\Database;

/** Data export (JSON) and complete deletion of a learner's data. */
final class AccountService
{
    /** Tables with a user_id column, exported in this order. */
    private const USER_TABLES = [
        'learning_sessions', 'answer_attempts', 'attempt_errors', 'conversation_turns', 'progression_decisions',
        'audio_transcripts', 'error_patterns', 'review_schedule', 'review_log', 'user_progress', 'user_lessons',
        'daily_activity', 'assessments', 'api_usage',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed> */
    public function export(int $userId): array
    {
        $user = $this->db->one('SELECT id, email, display_name, role, daily_goal_minutes, target_level, start_level, study_start_date, created_at, last_login_at FROM users WHERE id = ?', [$userId]);
        $data = ['exported_at' => gmdate('c'), 'format' => 'german-ai-coach-export-v1', 'user' => $user];
        foreach (self::USER_TABLES as $table) {
            $data[$table] = $this->db->all("SELECT * FROM `$table` WHERE user_id = ? ORDER BY 1", [$userId]);
        }
        return $data;
    }

    /**
     * Deletes the account. All learning data is removed by ON DELETE CASCADE.
     * api_usage rows keep only anonymous cost totals (user_id set to NULL) so the
     * monthly budget stays correct.
     */
    public function delete(int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($userId) {
            $db->execute('UPDATE api_usage SET user_id = NULL, session_id = NULL WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
        });
    }
}
