<?php

declare(strict_types=1);

namespace CodeVault;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper: lazy connection, prepared-statement helpers, and a
 * transaction closure. Every query goes through prepared statements —
 * no raw string interpolation anywhere in the core.
 */
class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $charset = 'utf8mb4'
    ) {
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";

            // MySQL restarts / brief outages should not instantly fatal the
            // whole request (that produced repeated "Can't connect to local
            // MySQL server through socket ... (111)" outages). Retry a couple
            // of times with short backoff; a genuine outage still surfaces as
            // a PDOException (logged), just after a ~600ms grace period.
            $attempts = 0;

            while ($this->pdo === null) {
                try {
                    $this->pdo = new PDO($dsn, $this->username, $this->password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                } catch (\PDOException $e) {
                    $attempts++;

                    if ($attempts >= 3) {
                        // Log connection details for debugging
                        error_log("Database connection failed: host={$this->host}, port={$this->port}, database={$this->database}, user={$this->username}");
                        throw $e;
                    }

                    usleep(200000 * $attempts);
                }
            }
        }

        return $this->pdo;
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt;
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    public function insert(string $sql, array $bindings = []): string
    {
        $this->statement($sql, $bindings);

        return $this->connection()->lastInsertId();
    }

    public function update(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    public function delete(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
