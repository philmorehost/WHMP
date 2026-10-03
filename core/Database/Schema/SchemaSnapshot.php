<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

/**
 * Builds and renders database/schema.php — the complete schema every install should
 * have, computed by replaying the migrations (MigrationReplay).
 *
 * The file is generated; bin/build-schema.php rewrites it and
 * tests/Unit/SchemaSnapshotTest.php fails when it is out of date, so adding a
 * migration without regenerating cannot slip through.
 */
final class SchemaSnapshot
{
    public function __construct(private readonly string $migrationsPath)
    {
    }

    /**
     * @return array{errors: array<int, string>, source: string, tables: int}
     */
    public function build(): array
    {
        $replay = new MigrationReplay($this->migrationsPath);
        $db = $replay->run();
        $files = $replay->files();

        return [
            'errors' => $db->model->errors,
            'source' => self::render($db->model->export(), (string) end($files), count($files)),
            'tables' => count($db->model->tables),
        ];
    }

    /** @param array<string, mixed> $tables */
    public static function render(array $tables, string $lastMigration, int $migrationCount): string
    {
        $data = [
            'last_migration' => $lastMigration,
            'migration_count' => $migrationCount,
            'tables' => $tables,
        ];

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "// GENERATED FILE — do not edit by hand. Rebuild with: php bin/build-schema.php\n"
            . "//\n"
            . "// The complete schema a fully migrated install has: every table, column, index and\n"
            . "// foreign key, computed by replaying database/migrations in order. On every live site\n"
            . "// SchemaReconciler compares the real database with this and ADDS whatever is missing\n"
            . "// (it never drops or rewrites anything), so a site that skipped a step — a migration\n"
            . "// that failed on its MySQL version, or one edited after the site had run it — still\n"
            . "// ends up with every table and column the code expects.\n\n"
            . 'return ' . self::export($data, 0) . ";\n";
    }

    private static function export(mixed $value, int $depth): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = $pad . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }
}
