<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Database;

/** Small key/value store for runtime switches (e.g. AI kill switch, emergency shutdown marker). */
final class AppSettings
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->db->scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $now = $this->clock->nowSql();
        $this->db->execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = ?',
            [$key, $value, $now, $value, $now]
        );
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM app_settings WHERE setting_key = ?', [$key]);
    }
}
