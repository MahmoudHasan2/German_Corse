<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Applies versioned SQL files from database/migrations in filename order.
 * Applied versions are recorded in schema_migrations with a checksum; re-running
 * is a no-op. Migrations are never edited after release — add a new file instead.
 *
 * Note: MySQL DDL causes implicit commits, so a migration cannot be rolled back
 * automatically. Back up before deploying (see docs/DEPLOYMENT_HOSTINGER.md).
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {
    }

    public function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,string> version => absolute path */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (preg_match('/^\d{3,}_[a-z0-9_]+$/', $version)) {
                $out[$version] = $file;
            }
        }
        return $out;
    }

    /** @return array<string,string> version => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->all('SELECT version, checksum FROM schema_migrations ORDER BY version');
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['version']] = (string) $r['checksum'];
        }
        return $out;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->available()), array_keys($this->applied())));
    }

    /** @return list<string> versions whose file changed after being applied */
    public function modified(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->available() as $version => $file) {
            if (isset($applied[$version]) && $applied[$version] !== hash_file('sha256', $file)) {
                $out[] = $version;
            }
        }
        return $out;
    }

    /** @return list<string> versions applied in this run */
    public function migrate(): array
    {
        $done = [];
        $available = $this->available();
        foreach ($this->pending() as $version) {
            $file = $available[$version];
            $sql = (string) file_get_contents($file);
            foreach (self::split($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run(
                'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP())',
                [$version, hash_file('sha256', $file)]
            );
            $done[] = $version;
        }
        return $done;
    }

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $statements = [];
        $buffer = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = rtrim(trim($buffer), ';');
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
