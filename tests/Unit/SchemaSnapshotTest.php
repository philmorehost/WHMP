<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Schema\MigrationReplay;
use CodeVault\Database\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * database/schema.php is what every live site is repaired towards, so it must be
 * exactly what the migrations build — and complete for what the code queries.
 */
final class SchemaSnapshotTest extends TestCase
{
    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_every_migration_replays_without_an_error_a_server_would_raise(): void
    {
        $db = (new MigrationReplay($this->base() . '/database/migrations'))->run();

        $this->assertSame([], $db->model->errors);
        $this->assertGreaterThan(100, count($db->model->tables));
    }

    public function test_the_snapshot_is_up_to_date_with_the_migrations(): void
    {
        $built = (new SchemaSnapshot($this->base() . '/database/migrations'))->build();

        $this->assertSame(
            $built['source'],
            (string) file_get_contents($this->base() . '/database/schema.php'),
            'database/schema.php is out of date — run: php bin/build-schema.php'
        );
    }

    public function test_the_snapshot_ends_at_the_newest_migration(): void
    {
        $snapshot = require $this->base() . '/database/schema.php';
        $files = (new MigrationReplay($this->base() . '/database/migrations'))->files();

        $this->assertSame(end($files), $snapshot['last_migration']);
        $this->assertSame(count($files), $snapshot['migration_count']);
    }

    public function test_every_table_the_code_queries_is_in_the_snapshot(): void
    {
        // The question asked of every live site is "does each table the code uses
        // exist?", so the snapshot has to contain all of them. Scans the SQL in the
        // application code (string literals that start like a query) for table names.
        $tables = (require $this->base() . '/database/schema.php')['tables'];
        $known = array_fill_keys(array_keys($tables), true) + [
            'migrations' => true,          // created by the Migrator itself
            'information_schema' => true,
            'dual' => true,
        ];

        $missing = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base() . '/core', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_contains($path, '/Import/') || str_contains($path, '/Database/Schema/')) {
                continue; // the WHMCS importer reads the OLD WHMCS database (tbl* tables)
            }

            $source = (string) file_get_contents($path);
            preg_match_all("/(['\"])\\s*((?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\\b(?:(?!\\1).)*)\\1|<<<'?SQL'?\\n(.*?)\\n\\s*SQL/s", $source, $literals, PREG_SET_ORDER);

            foreach ($literals as $literal) {
                $sql = ($literal[2] ?? '') !== '' ? $literal[2] : ($literal[3] ?? '');
                // `ON DUPLICATE KEY UPDATE col = ...` names columns, not a table.
                $sql = (string) preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b.*/is', '', $sql);
                preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z][a-z0-9_]*)`?(?=\s|$|,|\))/i', $sql, $names);

                foreach ($names[1] as $name) {
                    $name = strtolower($name);
                    // A word after FROM/UPDATE that is not a table: a subquery alias
                    // target, a keyword, or an interpolated name.
                    if (isset($known[$name]) || in_array($name, ['select', 'set', 'where', 'and', 'the', 'a'], true)) {
                        continue;
                    }
                    if (str_contains($sql, '{$') && !isset($tables[$name])) {
                        continue;
                    }
                    $missing[$name][] = substr($path, strlen($this->base()) + 1);
                }
            }
        }

        $missing = array_map(static fn (array $files): string => implode(', ', array_unique($files)), $missing);

        $this->assertSame([], $missing, 'tables queried by the code but created by no migration');
    }

    public function test_the_new_client_migrations_table_is_complete(): void
    {
        $table = (require $this->base() . '/database/schema.php')['tables']['client_migrations'] ?? null;

        $this->assertNotNull($table);
        foreach (['client_id', 'client_email', 'from_reseller_id', 'target', 'to_reseller_id', 'requested_by', 'ticket_id', 'status', 'summary', 'decided_at'] as $column) {
            $this->assertArrayHasKey($column, $table['columns']);
        }
        $this->assertCount(4, $table['foreign']);
    }
}
