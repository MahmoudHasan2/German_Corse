<?php

declare(strict_types=1);

namespace GermanCoach\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. All values go through prepared statements with
 * emulation disabled; identifiers are never taken from user input.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(private readonly \Closure $connector)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(static function () use ($config): PDO {
            $socket = $config->string('DB_SOCKET');
            $dsn = $socket !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $config->string('DB_NAME'))
                : sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config->string('DB_HOST', '127.0.0.1'),
                    $config->int('DB_PORT', 3306),
                    $config->string('DB_NAME')
                );
            $pdo = new PDO($dsn, $config->string('DB_USER'), $config->string('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // Work in UTC inside the database; convert for display only.
            $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            return $pdo;
        });
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ($this->connector)();
        }
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type === PDO::PARAM_BOOL ? PDO::PARAM_INT : $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<string|int,mixed> $params @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string|int,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Inserts a row. Column names must be trusted identifiers (they come from code, never from input).
     * @param array<string,mixed> $row
     */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        foreach ($cols as $c) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $c)) {
                throw new \InvalidArgumentException('Invalid column name.');
            }
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->run($sql, $row);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $fn($this);
            } finally {
                $this->transactionDepth--;
            }
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /** @param list<mixed> $values @return array{0:string,1:list<mixed>} placeholders and values for IN (...) */
    public static function inList(array $values): array
    {
        if ($values === []) {
            return ['NULL', []];
        }
        return [implode(', ', array_fill(0, count($values), '?')), array_values($values)];
    }
}
