<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

use CodeVault\Database;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * A Database that runs against a SchemaModel instead of a server.
 *
 * DDL changes the model; INFORMATION_SCHEMA queries are answered from it, which is
 * what lets the guarded migrations ("add this column only if it is missing") take
 * the same branch they would on a fresh install. Data reads return no rows and data
 * writes change nothing — only their table/column names are checked, because a real
 * server would reject those too.
 *
 * Used to build database/schema.php (bin/build-schema.php) and by the tests.
 */
final class SimulatedDatabase extends Database
{
    public function __construct(public readonly SchemaModel $model = new SchemaModel())
    {
        parent::__construct('', '', '', '', '');
    }

    public function connection(): PDO
    {
        throw new RuntimeException('SimulatedDatabase has no PDO connection; use statement()/select().');
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        if ($this->model->informationSchema($sql, $bindings) === null) {
            $this->model->apply($sql);
        }

        return new SimulatedStatement();
    }

    public function select(string $sql, array $bindings = []): array
    {
        $rows = $this->model->informationSchema($sql, $bindings);

        if ($rows !== null) {
            return $rows;
        }

        $this->checkRead($sql);

        return [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function insert(string $sql, array $bindings = []): string
    {
        $this->model->apply($sql);

        return '1';
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->model->apply($sql);

        return 0;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->model->apply($sql);

        return 0;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    /** A data read from a table that does not exist would fail on a real server. */
    private function checkRead(string $sql): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', $sql);

        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $flat, $m) && strcasecmp($m[1], 'DUAL') !== 0) {
            try {
                $this->model->requireTable(strtolower($m[1]));
            } catch (RuntimeException $e) {
                $this->model->errors[] = ($this->model->source !== '' ? $this->model->source . ': ' : '') . $e->getMessage() . ' — ' . mb_substr($flat, 0, 160);
            }
        }
    }
}
