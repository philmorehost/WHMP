<?php

declare(strict_types=1);

namespace CodeVault\Tests\Support;

use CodeVault\Database;

/**
 * A Database that never connects: each query is answered by the first scripted
 * handler whose pattern matches the SQL, and every write is recorded.
 *
 * For unit tests of code whose interesting behaviour is a DECISION made from a few
 * rows (who is this email for? which store owns this client?) — where standing up
 * MySQL would test the database rather than the decision. Anything touching real SQL
 * semantics (locks, constraints, NULL-safe comparisons) belongs in a
 * DatabaseTestCase instead.
 *
 * An unscripted SELECT returns no rows, which is the honest answer for "nothing
 * here" and keeps each test's script down to the rows it is actually about.
 */
final class ScriptedDatabase extends Database
{
    /** @var array<int, array{0: string, 1: callable}> */
    private array $handlers = [];

    /** @var array<int, array{sql: string, bindings: array<int, mixed>}> */
    public array $writes = [];

    private int $nextId = 1;

    public function __construct()
    {
        parent::__construct('', '', '', '', '');
    }

    /**
     * Answer queries whose SQL matches $pattern (a regex) with $rows, or with what
     * $rows(bindings, sql) returns.
     *
     * @param array<int, array<string, mixed>>|callable $rows
     */
    public function on(string $pattern, array|callable $rows): self
    {
        $this->handlers[] = [$pattern, is_callable($rows) ? $rows : static fn (): array => $rows];

        return $this;
    }

    public function select(string $sql, array $bindings = []): array
    {
        foreach ($this->handlers as [$pattern, $handler]) {
            if (preg_match($pattern, $sql) === 1) {
                return array_values($handler($bindings, $sql));
            }
        }

        return [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function insert(string $sql, array $bindings = []): string
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return (string) $this->nextId++;
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}
