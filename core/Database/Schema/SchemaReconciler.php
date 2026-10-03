<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

use CodeVault\Database;
use Throwable;

/**
 * Brings a live database up to database/schema.php by ADDING what is missing.
 *
 * WHY MIGRATIONS ALONE ARE NOT ENOUGH
 *
 * The Migrator records each migration by filename and never runs it again. That is
 * right for a migration that worked, and wrong for an install that is short of
 * something anyway — which happens:
 *
 *  - a migration failed on that server's MySQL version and was later rewritten as a
 *    no-op, its work moved into a CREATE TABLE that only fresh installs run
 *    (0117: domain_pricing's grace/redemption columns);
 *  - a migration was edited after the site had already recorded it;
 *  - a table was dropped or restored from an old backup.
 *
 * Every one of those surfaces later as "Table ... doesn't exist" or "Unknown column"
 * on some unrelated page. The reconciler closes the gap from the other end: it knows
 * what the schema SHOULD be (the snapshot built by replaying every migration), reads
 * what it IS (INFORMATION_SCHEMA), and adds the difference.
 *
 * WHAT IT DOES, AND WHAT IT NEVER DOES
 *
 *   missing table        created with its full current shape (columns, keys) and,
 *                        once every missing table exists, its foreign keys
 *   missing column       added (a DATE/DATETIME NOT NULL with no default is added as
 *                        NULL — existing rows have no value to give it, and strict
 *                        mode rejects the zero date)
 *   missing ENUM value   the column is widened, only when every value it has now is
 *                        still in the new list (a customised enum is left alone)
 *   missing index        added (a unique one that existing duplicate rows prevent
 *                        fails, is shown on System Diagnostics and retried hourly —
 *                        duplicates are never deleted to make it fit)
 *
 * It never drops, renames or narrows anything, never touches data, and never adds a
 * foreign key to a table that already existed (that rebuilds the whole table under
 * a lock, and orphaned rows would fail it) — those are reported instead. Every change
 * is its own statement, so one failure cannot block the rest.
 */
final class SchemaReconciler
{
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    public function __construct(
        private readonly Database $db,
        private readonly string $schemaFile
    ) {
    }

    // ---------------------------------------------------------------- expected ---

    /** @return array<string, array<string, mixed>> table => definition */
    public function expected(): array
    {
        return $this->snapshot()['tables'] ?? [];
    }

    /** A short fingerprint of the snapshot, so "already reconciled" can be remembered. */
    public function fingerprint(): string
    {
        return is_file($this->schemaFile) ? (string) sha1_file($this->schemaFile) : '';
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        if ($this->snapshot === null) {
            $loaded = is_file($this->schemaFile) ? (static fn (string $path): mixed => require $path)($this->schemaFile) : [];
            $this->snapshot = is_array($loaded) ? $loaded : [];
        }

        return $this->snapshot;
    }

    // -------------------------------------------------------------------- live ---

    /**
     * The live schema, from three INFORMATION_SCHEMA reads.
     *
     * @return array<string, array{columns: array<string, string>, indexes: array<string, true>, foreign: array<string, true>}>
     */
    public function inspect(): array
    {
        $live = [];

        foreach ($this->db->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $table = strtolower((string) $row['TABLE_NAME']);
            $live[$table] ??= ['columns' => [], 'indexes' => [], 'foreign' => []];
            $live[$table]['columns'][strtolower((string) $row['COLUMN_NAME'])] = (string) $row['COLUMN_TYPE'];
        }

        foreach ($this->db->select('SELECT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $table = strtolower((string) $row['TABLE_NAME']);
            if (isset($live[$table])) {
                $live[$table]['indexes'][strtolower((string) $row['INDEX_NAME'])] = true;
            }
        }

        foreach ($this->db->select("SELECT TABLE_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'") as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $table = strtolower((string) $row['TABLE_NAME']);
            if (isset($live[$table])) {
                $live[$table]['foreign'][strtolower((string) $row['CONSTRAINT_NAME'])] = true;
            }
        }

        return $live;
    }

    // -------------------------------------------------------------------- plan ---

    /**
     * Everything that differs, as the statements that would fix it.
     *
     * kind: table | column | enum | index | foreign   (applied)
     *       report                                    (needs a person; not applied)
     *
     * @return array<int, array{kind: string, table: string, name: string, sql: ?string, note: string}>
     */
    public function plan(?array $live = null): array
    {
        $live ??= $this->inspect();
        $expected = $this->expected();
        $steps = [];
        $newTables = [];

        foreach ($expected as $table => $definition) {
            if (!isset($live[$table])) {
                $newTables[$table] = true;
                $steps[] = $this->step('table', $table, $table, self::createTableSql($table, $definition), 'missing table');
                continue;
            }

            $have = $live[$table];
            // The column a missing one goes after, so it lands where a fresh install
            // has it. Steps run in order, so an earlier added column exists by then.
            $previous = null;

            foreach ($definition['columns'] as $column => $columnDefinition) {
                if (!isset($have['columns'][$column])) {
                    if (preg_match('/\bAUTO_INCREMENT\b/i', SchemaModel::mask($columnDefinition))) {
                        $steps[] = $this->step('report', $table, $column, null, 'missing AUTO_INCREMENT column — needs manual repair');
                        continue;
                    }
                    $steps[] = $this->step('column', $table, $column, sprintf(
                        'ALTER TABLE `%s` ADD COLUMN `%s` %s %s',
                        $table,
                        $column,
                        self::definitionForExistingRows($columnDefinition),
                        $previous === null ? 'FIRST' : "AFTER `{$previous}`"
                    ), 'missing column');
                    $previous = $column;
                    continue;
                }

                $previous = $column;

                // ENUM/SET that lacks values the code now writes.
                $want = SchemaModel::enumValues(SchemaModel::columnType($columnDefinition));
                $got = SchemaModel::enumValues(SchemaModel::columnType($have['columns'][$column]));
                if ($want !== null && $got !== null && array_diff($want, $got) !== []) {
                    if (array_diff($got, $want) === []) {
                        $steps[] = $this->step('enum', $table, $column, sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $columnDefinition), 'missing value(s): ' . implode(', ', array_diff($want, $got)));
                    } else {
                        $steps[] = $this->step('report', $table, $column, null, 'enum differs and has custom values: ' . implode(', ', array_diff($got, $want)));
                    }
                }
            }

            $columnsAfter = array_keys($have['columns']) + [];
            foreach (array_keys($definition['columns']) as $column) {
                $columnsAfter[] = $column;
            }

            foreach ($definition['indexes'] as $index => $indexDefinition) {
                if (isset($have['indexes'][$index])) {
                    continue;
                }
                $missingColumn = false;
                foreach ($indexDefinition['columns'] as $spec) {
                    if (!in_array(SchemaModel::columnOf($spec), $columnsAfter, true)) {
                        $missingColumn = true;
                    }
                }
                if ($missingColumn) {
                    continue;
                }
                $steps[] = $this->step('index', $table, $index, sprintf('ALTER TABLE `%s` ADD %s', $table, self::indexClause($index, $indexDefinition)), 'missing index');
            }

            if ($definition['primary'] !== [] && !isset($have['indexes']['primary'])) {
                $steps[] = $this->step('report', $table, 'PRIMARY', null, 'table has no primary key — needs manual repair');
            }

            foreach ($definition['foreign'] as $fk => $foreign) {
                if (!isset($have['foreign'][$fk])) {
                    $steps[] = $this->step('report', $table, $fk, null, 'foreign key missing on an existing table (not added automatically): ' . self::foreignClause($fk, $foreign));
                }
            }
        }

        // Foreign keys for the tables created above, after all of them exist.
        foreach (array_keys($newTables) as $table) {
            foreach ($expected[$table]['foreign'] as $fk => $foreign) {
                $steps[] = $this->step('foreign', $table, $fk, sprintf('ALTER TABLE `%s` ADD %s', $table, self::foreignClause($fk, $foreign)), 'foreign key for new table');
            }
        }

        return $steps;
    }

    /**
     * Apply every fixable step.
     *
     * @return array{applied: array<int, string>, failed: array<string, string>, reported: array<int, string>}
     */
    public function reconcile(): array
    {
        $applied = [];
        $failed = [];
        $reported = [];

        foreach ($this->plan() as $step) {
            $label = "{$step['kind']} {$step['table']}" . ($step['name'] !== $step['table'] ? ".{$step['name']}" : '');

            if ($step['sql'] === null) {
                $reported[] = "{$label}: {$step['note']}";
                continue;
            }

            try {
                $this->db->statement($step['sql']);
                $applied[] = $label;
            } catch (Throwable $e) {
                // Another request reconciling at the same moment (two visitors right
                // after an upload) got there first: the thing exists now, which is
                // all this step wanted.
                if ($e instanceof \PDOException
                    && in_array((int) ($e->errorInfo[1] ?? 0), \CodeVault\Database\Migrator::ALREADY_DONE_ERRORS, true)
                ) {
                    $applied[] = $label . ' (already present)';
                    continue;
                }

                $failed[$label] = $e->getMessage();
                error_log("[CodeVault] schema repair failed ({$label}): " . $e->getMessage());
            }
        }

        return ['applied' => $applied, 'failed' => $failed, 'reported' => $reported];
    }

    /**
     * Reconcile when the snapshot has changed since the last successful run (i.e. after
     * a deploy), or when the last run had failures and an hour has passed. Remembered
     * in a small JSON file so an ordinary request costs one file read.
     *
     * @return array{applied: array<int, string>, failed: array<string, string>, reported: array<int, string>}|null null when not due
     */
    public function reconcileIfDue(string $markerFile, bool $force = false): ?array
    {
        $fingerprint = $this->fingerprint();

        if ($fingerprint === '') {
            return null; // no snapshot shipped: nothing to compare against
        }

        $marker = is_file($markerFile) ? json_decode((string) @file_get_contents($markerFile), true) : null;
        $marker = is_array($marker) ? $marker : [];
        $sameSnapshot = ($marker['fingerprint'] ?? '') === $fingerprint;
        $retryDue = !($marker['ok'] ?? false) && (time() - (int) ($marker['at'] ?? 0)) >= 3600;

        if (!$force && $sameSnapshot && !$retryDue) {
            return null;
        }

        $result = $this->reconcile();

        $directory = dirname($markerFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($markerFile, (string) json_encode([
            'fingerprint' => $fingerprint,
            'at' => time(),
            'ok' => $result['failed'] === [],
            'applied' => $result['applied'],
            'failed' => $result['failed'],
            'reported' => $result['reported'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result['applied'] !== []) {
            error_log('[CodeVault] schema repaired: ' . implode('; ', $result['applied']));
        }

        return $result;
    }

    // ------------------------------------------------------------- SQL building ---

    /** @param array<string, mixed> $definition */
    public static function createTableSql(string $table, array $definition): string
    {
        $lines = [];

        foreach ($definition['columns'] as $column => $columnDefinition) {
            $lines[] = sprintf('`%s` %s', $column, $columnDefinition);
        }

        if ($definition['primary'] !== []) {
            $lines[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $definition['primary'])) . ')';
        }

        foreach ($definition['indexes'] as $index => $indexDefinition) {
            $lines[] = self::indexClause($index, $indexDefinition);
        }

        return sprintf("CREATE TABLE IF NOT EXISTS `%s` (\n    %s\n) %s", $table, implode(",\n    ", $lines), $definition['options'] !== '' ? $definition['options'] : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** @param array{unique: bool, fulltext: bool, columns: array<int, string>} $index */
    private static function indexClause(string $name, array $index): string
    {
        $columns = array_map(static function (string $spec): string {
            $column = SchemaModel::columnOf($spec);

            return '`' . $column . '`' . substr(trim(str_replace('`', '', $spec)), strlen($column));
        }, $index['columns']);

        $kind = $index['fulltext'] ? 'FULLTEXT KEY' : ($index['unique'] ? 'UNIQUE KEY' : 'KEY');

        return sprintf('%s `%s` (%s)', $kind, $name, implode(', ', $columns));
    }

    /** @param array{column: string, references: string, referenced_column: string, on_delete: ?string, on_update: ?string} $foreign */
    private static function foreignClause(string $name, array $foreign): string
    {
        return sprintf('CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)', $name, $foreign['column'], $foreign['references'], $foreign['referenced_column'])
            . ($foreign['on_delete'] !== null ? ' ON DELETE ' . $foreign['on_delete'] : '')
            . ($foreign['on_update'] !== null ? ' ON UPDATE ' . $foreign['on_update'] : '');
    }

    /**
     * A column definition safe to add to a table that already has rows: a date/time
     * column that is NOT NULL with no default would need a zero date for those rows,
     * which strict mode rejects, so it is added as NULL instead.
     */
    public static function definitionForExistingRows(string $definition): string
    {
        $masked = SchemaModel::mask($definition);
        $type = strtoupper((string) preg_replace('/[\s(].*$/s', '', trim($definition)));

        if (in_array($type, ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME'], true)
            && preg_match('/\bNOT\s+NULL\b/i', $masked, $m, PREG_OFFSET_CAPTURE)
            && !preg_match('/\bDEFAULT\b/i', $masked)
        ) {
            return substr($definition, 0, $m[0][1]) . 'NULL' . substr($definition, $m[0][1] + strlen($m[0][0]));
        }

        return $definition;
    }

    /** @return array{kind: string, table: string, name: string, sql: ?string, note: string} */
    private function step(string $kind, string $table, string $name, ?string $sql, string $note): array
    {
        return ['kind' => $kind, 'table' => $table, 'name' => $name, 'sql' => $sql, 'note' => $note];
    }
}
