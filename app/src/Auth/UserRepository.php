<?php

declare(strict_types=1);

namespace GermanCoach\Auth;

use GermanCoach\Core\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users');
    }

    public function create(string $email, string $password, string $displayName, string $role = 'learner'): int
    {
        return $this->db->insert('users', [
            'email' => mb_strtolower($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'role' => $role,
            'study_start_date' => gmdate('Y-m-d'),
        ]);
    }

    public function touchLogin(int $id): void
    {
        $this->db->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    /** @param array{display_name?:string,daily_goal_minutes?:int,target_level?:string} $fields */
    public function updateProfile(int $id, array $fields): void
    {
        $allowed = ['display_name', 'daily_goal_minutes', 'target_level'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "`$k` = ?";
                $params[] = $v;
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function setStartLevel(int $id, string $level): void
    {
        $this->db->execute('UPDATE users SET start_level = ? WHERE id = ?', [$level, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function publicView(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'role' => (string) $user['role'],
            'daily_goal_minutes' => (int) $user['daily_goal_minutes'],
            'target_level' => (string) $user['target_level'],
            'start_level' => $user['start_level'] !== null ? (string) $user['start_level'] : null,
            'study_start_date' => $user['study_start_date'] !== null ? (string) $user['study_start_date'] : null,
        ];
    }
}
