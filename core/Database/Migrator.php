<?php

declare(strict_types=1);

namespace CodeVault\Database;

use CodeVault\Database;
use RuntimeException;

/**
 * Minimal migration runner. Schema grows incrementally per phase (R1 adds
 * only what the installer/licensing need; billing/product/domain/ticket
 * tables land with the phases that actually use them — see blueprint §6/§8)
 * rather than one static full-DDL file written months before the logic
 * that needs it exists.
 *
 * Each file in the migrations directory returns `['up' => [sql, sql, ...]]`
 * and is named so lexical sort == run order, e.g. `0001_create_admins_table.php`.
 */
class Migrator
{
    /** @var array<string, string> filename => error, filled by run(true) */
    private array $failures = [];

    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath
    ) {
    }

    public function ensureMigrationsTable(): void
    {
        $this->db->connection()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL UNIQUE,
                run_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    /** @return array<int, string> migration filenames already applied */
    public function applied(): array
    {
        $this->ensureMigrationsTable();

        return array_column($this->db->select('SELECT migration FROM migrations ORDER BY migration'), 'migration');
    }

    /** @return array<int, string> migration filenames not yet applied, in run order */
    public function pending(): array
    {
        $applied = $this->applied();
        $all = $this->allMigrationFiles();

        return array_values(array_diff($all, $applied));
    }

    /**
     * @param bool $continueOnError when true a failing migration is logged and
     *        skipped instead of aborting the whole run. The boot path passes true;
     *        `bin/migrate.php` does NOT, so an operator sees the error and the
     *        non-zero exit.
     * @return array<int, string> filenames that were applied by this call
     */
    public function run(bool $continueOnError = false): array
    {
        $this->ensureMigrationsTable();
        $this->failures = [];
        $ran = [];

        foreach ($this->pending() as $filename) {
            try {
                $this->apply($filename);
                $ran[] = $filename;
            } catch (\Throwable $e) {
                if (!$continueOnError) {
                    throw $e;
                }

                // ONE broken migration must not block EVERY later one. Aborting
                // the run leaves the schema short by this file AND by every
                // migration after it — which is exactly how a live site came to
                // be missing several unrelated tables (reseller_payouts among
                // them) at the same time, each surfacing as its own
                // "Table ... doesn't exist" fatal on a different admin page.
                //
                // The file is deliberately NOT recorded as applied, so it is
                // retried on the next boot; it is collected and logged so the
                // cause is visible rather than silent (see Migrator::failures()).
                $this->failures[$filename] = $e->getMessage();
                error_log("[CodeVault] migration {$filename} failed: " . $e->getMessage());
            }
        }

        return $ran;
    }

    /** @return array<string, string> filename => error from the most recent run() */
    public function failures(): array
    {
        return $this->failures;
    }

    private function apply(string $filename): void
    {
        $definition = require $this->migrationsPath . '/' . $filename;

        if (!is_array($definition) || !isset($definition['up']) || !is_array($definition['up'])) {
            throw new RuntimeException("Migration [{$filename}] must return ['up' => [...sql statements]].");
        }

        // Not wrapped in a transaction: DDL (CREATE TABLE, etc.) causes
        // an implicit commit in MySQL/MariaDB, which would leave a
        // later commit()/rollback() call with no active transaction.
        //
        // A statement may be a raw SQL string, or a closure(Database $db)
        // for migrations that need to branch on current schema state
        // (e.g. "add this column only if it's missing") — needed because
        // `ADD COLUMN IF NOT EXISTS` is MySQL 8.0.29+/MariaDB-only syntax
        // and isn't safe to rely on across hosts (see migration 0117).
        foreach ($definition['up'] as $statement) {
            if ($statement instanceof \Closure) {
                $statement($this->db);
                continue;
            }

            // Use prepared statement to ensure proper buffering and result cleanup
            $stmt = $this->db->connection()->prepare($statement);
            $stmt->execute();
            // Explicitly close the statement to release any locks
            $stmt = null;
        }

        $this->db->insert(
            'INSERT INTO migrations (migration, run_at) VALUES (?, NOW())',
            [$filename]
        );
    }

    /** @return array<int, string> */
    private function allMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php') ?: [];
        $names = array_map('basename', $files);
        sort($names);

        return $names;
    }
}
