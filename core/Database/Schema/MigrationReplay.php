<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

use RuntimeException;
use Throwable;

/**
 * Runs every migration, in order, against a SimulatedDatabase — the schema a complete
 * fresh install ends up with, computed without a server.
 *
 * Statements are applied exactly as Migrator applies them: SQL strings one by one,
 * closures called with the database. A closure that throws is recorded as an error
 * (on a real server that migration would fail and be retried forever).
 */
final class MigrationReplay
{
    public function __construct(private readonly string $migrationsPath)
    {
    }

    /** @param array<int, string>|null $only run just these files (default: all) */
    public function run(?array $only = null, ?SimulatedDatabase $db = null): SimulatedDatabase
    {
        $db ??= new SimulatedDatabase();

        foreach ($this->files() as $file) {
            if ($only !== null && !in_array($file, $only, true)) {
                continue;
            }

            $this->apply($db, $file);
        }

        $db->model->source = '';

        return $db;
    }

    public function apply(SimulatedDatabase $db, string $file): void
    {
        $db->model->source = $file;

        $definition = (static function (string $path): mixed {
            return require $path;
        })($this->migrationsPath . '/' . $file);

        if (!is_array($definition) || !isset($definition['up']) || !is_array($definition['up'])) {
            $db->model->errors[] = "{$file}: must return ['up' => [...]]";

            return;
        }

        foreach ($definition['up'] as $statement) {
            try {
                if ($statement instanceof \Closure) {
                    $statement($db);
                } elseif (is_string($statement)) {
                    $db->statement($statement);
                } else {
                    throw new RuntimeException('statement is neither SQL nor a closure');
                }
            } catch (Throwable $e) {
                $db->model->errors[] = "{$file}: " . $e->getMessage();
            }
        }
    }

    /** @return array<int, string> migration filenames in run order (as Migrator sorts them) */
    public function files(): array
    {
        $names = array_map('basename', glob($this->migrationsPath . '/*.php') ?: []);
        sort($names);

        return $names;
    }
}
