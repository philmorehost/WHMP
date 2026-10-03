<?php

declare(strict_types=1);

namespace CodeVault\Database\Schema;

use RuntimeException;

/**
 * An in-memory model of a MySQL schema, driven by the same DDL the migrations run.
 *
 * WHY THIS EXISTS
 *
 * The migrations are the only description of the schema, and they are a HISTORY, not a
 * snapshot: 200+ files of CREATE / ALTER, many of them closures that probe
 * INFORMATION_SCHEMA before acting. A live site that ever skipped a step — a migration
 * that failed on its MySQL version and was later rewritten, a file edited after the
 * site had already recorded it — is short of a table or a column, and nothing notices
 * until a page dies on "Table ... doesn't exist".
 *
 * Replaying every migration through this model (see SimulatedDatabase and
 * bin/build-schema.php) produces the exact end state a complete install has. That end
 * state is written to database/schema.php, and SchemaReconciler compares each live
 * database against it and adds whatever is missing.
 *
 * WHAT IT UNDERSTANDS
 *
 * The DDL this codebase actually uses — CREATE/DROP/RENAME TABLE, CREATE/DROP INDEX, and
 * ALTER TABLE with ADD/MODIFY/CHANGE/DROP/RENAME of columns, indexes and constraints —
 * plus SELECTs against INFORMATION_SCHEMA.COLUMNS / STATISTICS / TABLE_CONSTRAINTS /
 * TABLES, which is how the guarded migrations decide what to do. Anything it does not
 * understand is an ERROR rather than a silent skip, so a new migration shape cannot
 * quietly fall out of the snapshot.
 *
 * Names are folded to lower case: column and index names are case-insensitive in
 * MySQL, and every table in this schema is lower case.
 */
final class SchemaModel
{
    /**
     * @var array<string, array{
     *     columns: array<string, string>,
     *     primary: array<int, string>,
     *     indexes: array<string, array{unique: bool, fulltext: bool, columns: array<int, string>}>,
     *     foreign: array<string, array{column: string, references: string, referenced_column: string, on_delete: ?string, on_update: ?string}>,
     *     options: string
     * }>
     */
    public array $tables = [];

    /** @var array<int, string> problems a real server would have rejected */
    public array $errors = [];

    /** Set by the replay so errors say which migration they came from. */
    public string $source = '';

    // ------------------------------------------------------------------ DDL ---

    /**
     * Apply one statement. DML is checked (the table and listed columns must exist,
     * because a real server would reject it) but otherwise has no effect on the model.
     */
    public function apply(string $sql): void
    {
        $sql = trim(self::stripComments($sql));
        $sql = rtrim($sql, "; \t\n\r");

        if ($sql === '') {
            return;
        }

        $flat = (string) preg_replace('/\s+/', ' ', $sql);

        try {
            if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)\s*([^()]*)$/is', $sql, $m)) {
                $this->createTable(strtolower($m[2]), $m[3], trim($m[4]), $m[1] !== '');
            } elseif (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+LIKE\s+`?(\w+)`?$/i', $flat, $m)) {
                $this->createTableLike(strtolower($m[2]), strtolower($m[3]), $m[1] !== '');
            } elseif (preg_match('/^ALTER\s+(?:IGNORE\s+)?TABLE\s+`?(\w+)`?\s+(.*)$/is', $sql, $m)) {
                $this->alterTable(strtolower($m[1]), $m[2]);
            } elseif (preg_match('/^RENAME\s+TABLE\s+(.*)$/is', $flat, $m)) {
                foreach (self::splitTopLevel($m[1]) as $pair) {
                    if (!preg_match('/^`?(\w+)`?\s+TO\s+`?(\w+)`?$/i', trim($pair), $p)) {
                        throw new RuntimeException("cannot read RENAME TABLE clause [{$pair}]");
                    }
                    $this->renameTable(strtolower($p[1]), strtolower($p[2]));
                }
            } elseif (preg_match('/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?(.*)$/is', $flat, $m)) {
                foreach (self::splitTopLevel($m[2]) as $name) {
                    $name = strtolower(trim($name, " `"));
                    if (!isset($this->tables[$name]) && $m[1] === '') {
                        throw new RuntimeException("DROP TABLE {$name}: table does not exist");
                    }
                    unset($this->tables[$name]);
                }
            } elseif (preg_match('/^CREATE\s+(UNIQUE\s+|FULLTEXT\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?\s*\((.*)\)$/is', $flat, $m)) {
                $this->addIndex(strtolower($m[3]), strtolower($m[2]), $m[4], stripos($m[1], 'UNIQUE') !== false, stripos($m[1], 'FULLTEXT') !== false);
            } elseif (preg_match('/^DROP\s+INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?$/i', $flat, $m)) {
                $this->dropIndex(strtolower($m[2]), strtolower($m[1]));
            } elseif (preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|SELECT|SET|TRUNCATE|DO)\b/i', $flat)) {
                $this->checkDml($flat);
            } else {
                throw new RuntimeException('unrecognised statement');
            }
        } catch (RuntimeException $e) {
            $this->errors[] = ($this->source !== '' ? $this->source . ': ' : '') . $e->getMessage() . ' — ' . mb_substr($flat, 0, 160);
        }
    }

    private function createTable(string $table, string $body, string $options, bool $ifNotExists): void
    {
        if (isset($this->tables[$table])) {
            if ($ifNotExists) {
                return;
            }

            throw new RuntimeException("CREATE TABLE {$table}: table already exists");
        }

        $this->tables[$table] = [
            'columns' => [],
            'primary' => [],
            'indexes' => [],
            'foreign' => [],
            'options' => self::normaliseSpace($options),
        ];

        $foreign = [];

        foreach (self::splitTopLevel($body) as $item) {
            $item = trim($item);
            $masked = self::mask($item);

            if (preg_match('/^PRIMARY\s+KEY\s*(?:USING\s+\w+\s*)?\((.*)\)/is', $item, $m)) {
                $this->tables[$table]['primary'] = self::indexColumnNames($m[1]);
            } elseif (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?FOREIGN\s+KEY\b/i', $masked)) {
                $foreign[] = $item;
            } elseif (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?UNIQUE\s*(?:INDEX|KEY)?\s*`?(\w+)?`?\s*\((.*)\)\s*$/is', $item, $m)) {
                $name = ($m[2] ?? '') !== '' ? $m[2] : (($m[1] ?? '') !== '' ? $m[1] : '');
                $this->addIndex($table, strtolower($name), $m[3], true, false);
            } elseif (preg_match('/^(FULLTEXT\s+)?(?:INDEX|KEY)\s*`?(\w+)?`?\s*\((.*)\)\s*$/is', $item, $m)) {
                $this->addIndex($table, strtolower($m[2] ?? ''), $m[3], false, $m[1] !== '');
            } elseif (preg_match('/^FULLTEXT\s*`?(\w+)?`?\s*\((.*)\)\s*$/is', $item, $m)) {
                $this->addIndex($table, strtolower($m[1] ?? ''), $m[2], false, true);
            } elseif (preg_match('/^(?:CONSTRAINT\s+`?\w+`?\s+)?CHECK\s*\(/i', $item)) {
                continue; // CHECK constraints are not part of what the reconciler restores
            } else {
                $this->addColumn($table, $item, false);
            }
        }

        // Foreign keys last: MySQL creates an implicit index for one only if no index
        // already starts with its column, and the explicit indexes must be known first.
        foreach ($foreign as $item) {
            $this->addForeignKeyClause($table, $item);
        }
    }

    private function createTableLike(string $table, string $source, bool $ifNotExists): void
    {
        if (isset($this->tables[$table])) {
            if ($ifNotExists) {
                return;
            }
            throw new RuntimeException("CREATE TABLE {$table}: table already exists");
        }
        if (!isset($this->tables[$source])) {
            throw new RuntimeException("CREATE TABLE {$table} LIKE {$source}: source does not exist");
        }

        $copy = $this->tables[$source];
        $copy['foreign'] = []; // LIKE does not copy foreign keys
        $this->tables[$table] = $copy;
    }

    private function alterTable(string $table, string $specs): void
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException("ALTER TABLE {$table}: table does not exist");
        }

        foreach (self::splitTopLevel($specs) as $spec) {
            $spec = trim($spec);
            $masked = self::mask($spec);

            if (preg_match('/^ADD\s+(?:CONSTRAINT\s+`?(\w+)`?\s+)?FOREIGN\s+KEY\b/i', $masked)) {
                $this->addForeignKeyClause($table, (string) preg_replace('/^ADD\s+/i', '', $spec));
            } elseif (preg_match('/^ADD\s+(?:CONSTRAINT\s+`?(\w+)`?\s+)?UNIQUE\s*(?:INDEX|KEY)?\s*`?(\w+)?`?\s*\((.*)\)\s*$/is', $spec, $m)) {
                $name = ($m[2] ?? '') !== '' ? $m[2] : (($m[1] ?? '') !== '' ? $m[1] : '');
                $this->addIndex($table, strtolower($name), $m[3], true, false);
            } elseif (preg_match('/^ADD\s+(FULLTEXT\s+)?(?:INDEX|KEY)\s*`?(\w+)?`?\s*\((.*)\)\s*$/is', $spec, $m)) {
                $this->addIndex($table, strtolower($m[2] ?? ''), $m[3], false, $m[1] !== '');
            } elseif (preg_match('/^ADD\s+PRIMARY\s+KEY\s*\((.*)\)$/is', $spec, $m)) {
                if ($this->tables[$table]['primary'] !== []) {
                    throw new RuntimeException("ALTER TABLE {$table}: multiple primary key defined");
                }
                $this->tables[$table]['primary'] = self::indexColumnNames($m[1]);
            } elseif (preg_match('/^ADD\s+(?:CONSTRAINT\s+`?\w+`?\s+)?CHECK\b/i', $spec)) {
                continue;
            } elseif (preg_match('/^ADD\s+(?:COLUMN\s+)?\((.*)\)$/is', $spec, $m) && !preg_match('/^ADD\s+(?:COLUMN\s+)?\(\s*\w+\s*\)/i', $spec)) {
                foreach (self::splitTopLevel($m[1]) as $column) {
                    $this->addColumn($table, $column, true);
                }
            } elseif (preg_match('/^ADD\s+(?:COLUMN\s+)?(.*)$/is', $spec, $m)) {
                $this->addColumn($table, $m[1], true);
            } elseif (preg_match('/^MODIFY\s+(?:COLUMN\s+)?(.*)$/is', $spec, $m)) {
                $this->modifyColumn($table, $m[1]);
            } elseif (preg_match('/^CHANGE\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.*)$/is', $spec, $m)) {
                $this->changeColumn($table, strtolower($m[1]), $m[2]);
            } elseif (preg_match('/^DROP\s+FOREIGN\s+KEY\s+`?(\w+)`?$/i', $spec, $m)) {
                $name = strtolower($m[1]);
                if (!isset($this->tables[$table]['foreign'][$name])) {
                    throw new RuntimeException("ALTER TABLE {$table} DROP FOREIGN KEY {$name}: no such constraint");
                }
                unset($this->tables[$table]['foreign'][$name]);
            } elseif (preg_match('/^DROP\s+PRIMARY\s+KEY$/i', $spec)) {
                $this->tables[$table]['primary'] = [];
            } elseif (preg_match('/^DROP\s+(?:INDEX|KEY)\s+`?(\w+)`?$/i', $spec, $m)) {
                $this->dropIndex($table, strtolower($m[1]));
            } elseif (preg_match('/^DROP\s+(?:CONSTRAINT|CHECK)\s+`?(\w+)`?$/i', $spec, $m)) {
                $name = strtolower($m[1]);
                unset($this->tables[$table]['foreign'][$name], $this->tables[$table]['indexes'][$name]);
            } elseif (preg_match('/^DROP\s+(?:COLUMN\s+)?`?(\w+)`?$/i', $spec, $m)) {
                $this->dropColumn($table, strtolower($m[1]));
            } elseif (preg_match('/^RENAME\s+COLUMN\s+`?(\w+)`?\s+TO\s+`?(\w+)`?$/i', $spec, $m)) {
                $this->renameColumn($table, strtolower($m[1]), strtolower($m[2]));
            } elseif (preg_match('/^RENAME\s+(?:INDEX|KEY)\s+`?(\w+)`?\s+TO\s+`?(\w+)`?$/i', $spec, $m)) {
                $from = strtolower($m[1]);
                $to = strtolower($m[2]);
                if (!isset($this->tables[$table]['indexes'][$from])) {
                    throw new RuntimeException("ALTER TABLE {$table} RENAME INDEX {$from}: no such index");
                }
                $this->tables[$table]['indexes'][$to] = $this->tables[$table]['indexes'][$from];
                unset($this->tables[$table]['indexes'][$from]);
            } elseif (preg_match('/^RENAME\s+(?:TO\s+|AS\s+)?`?(\w+)`?$/i', $spec, $m)) {
                $this->renameTable($table, strtolower($m[1]));
                $table = strtolower($m[1]);
            } elseif (preg_match('/^ALTER\s+(?:COLUMN\s+)?`?(\w+)`?\s+(SET\s+DEFAULT\s+(.*)|DROP\s+DEFAULT)$/is', $spec, $m)) {
                $this->alterDefault($table, strtolower($m[1]), isset($m[3]) ? trim($m[3]) : null);
            } elseif (preg_match('/^(ENGINE|CONVERT\s+TO|(DEFAULT\s+)?(CHARACTER\s+SET|CHARSET|COLLATE)|AUTO_INCREMENT|ROW_FORMAT|COMMENT|ALGORITHM|LOCK|FORCE)\b/i', $spec)) {
                continue; // table options; nothing the reconciler restores
            } else {
                throw new RuntimeException("ALTER TABLE {$table}: unrecognised clause [{$spec}]");
            }
        }
    }

    // --------------------------------------------------------------- columns ---

    private function addColumn(string $table, string $text, bool $viaAlter): void
    {
        [$name, $definition, $inline] = self::parseColumn($text);

        if (isset($this->tables[$table]['columns'][$name])) {
            throw new RuntimeException("{$table}.{$name}: duplicate column name");
        }

        $this->tables[$table]['columns'] = self::insertAt($this->tables[$table]['columns'], $name, $definition, $inline['position']);
        $this->applyInlineKeys($table, $name, $inline);
    }

    private function modifyColumn(string $table, string $text): void
    {
        [$name, $definition, $inline] = self::parseColumn($text);

        if (!isset($this->tables[$table]['columns'][$name])) {
            throw new RuntimeException("MODIFY {$table}.{$name}: unknown column");
        }

        if ($inline['position'] !== null) {
            unset($this->tables[$table]['columns'][$name]);
            $this->tables[$table]['columns'] = self::insertAt($this->tables[$table]['columns'], $name, $definition, $inline['position']);
        } else {
            $this->tables[$table]['columns'][$name] = $definition;
        }

        $this->applyInlineKeys($table, $name, $inline);
    }

    private function changeColumn(string $table, string $old, string $text): void
    {
        [$name, $definition, $inline] = self::parseColumn($text);

        if (!isset($this->tables[$table]['columns'][$old])) {
            throw new RuntimeException("CHANGE {$table}.{$old}: unknown column");
        }
        if ($name !== $old && isset($this->tables[$table]['columns'][$name])) {
            throw new RuntimeException("CHANGE {$table}.{$old} to {$name}: duplicate column name");
        }

        $rebuilt = [];
        foreach ($this->tables[$table]['columns'] as $column => $existing) {
            $rebuilt[$column === $old ? $name : $column] = $column === $old ? $definition : $existing;
        }
        $this->tables[$table]['columns'] = $rebuilt;
        $this->renameColumnReferences($table, $old, $name);

        if ($inline['position'] !== null) {
            unset($this->tables[$table]['columns'][$name]);
            $this->tables[$table]['columns'] = self::insertAt($this->tables[$table]['columns'], $name, $definition, $inline['position']);
        }

        $this->applyInlineKeys($table, $name, $inline);
    }

    private function renameColumn(string $table, string $old, string $new): void
    {
        if (!isset($this->tables[$table]['columns'][$old])) {
            throw new RuntimeException("RENAME COLUMN {$table}.{$old}: unknown column");
        }

        $rebuilt = [];
        foreach ($this->tables[$table]['columns'] as $column => $definition) {
            $rebuilt[$column === $old ? $new : $column] = $definition;
        }
        $this->tables[$table]['columns'] = $rebuilt;
        $this->renameColumnReferences($table, $old, $new);
    }

    private function renameColumnReferences(string $table, string $old, string $new): void
    {
        if ($old === $new) {
            return;
        }

        $this->tables[$table]['primary'] = array_map(static fn (string $c): string => $c === $old ? $new : $c, $this->tables[$table]['primary']);

        foreach ($this->tables[$table]['indexes'] as $index => $definition) {
            $this->tables[$table]['indexes'][$index]['columns'] = array_map(
                static fn (string $spec): string => self::columnOf($spec) === $old ? (string) preg_replace('/^`?\w+`?/', $new, $spec) : $spec,
                $definition['columns']
            );
        }

        foreach ($this->tables[$table]['foreign'] as $fk => $definition) {
            if ($definition['column'] === $old) {
                $this->tables[$table]['foreign'][$fk]['column'] = $new;
            }
        }

        foreach ($this->tables as $other => $schema) {
            foreach ($schema['foreign'] as $fk => $definition) {
                if ($definition['references'] === $table && $definition['referenced_column'] === $old) {
                    $this->tables[$other]['foreign'][$fk]['referenced_column'] = $new;
                }
            }
        }
    }

    private function dropColumn(string $table, string $column): void
    {
        if (!isset($this->tables[$table]['columns'][$column])) {
            throw new RuntimeException("DROP COLUMN {$table}.{$column}: unknown column");
        }

        unset($this->tables[$table]['columns'][$column]);

        // As MySQL does: the column leaves every index, and an index left empty goes.
        $this->tables[$table]['primary'] = array_values(array_filter($this->tables[$table]['primary'], static fn (string $c): bool => $c !== $column));

        foreach ($this->tables[$table]['indexes'] as $index => $definition) {
            $remaining = array_values(array_filter($definition['columns'], static fn (string $spec): bool => self::columnOf($spec) !== $column));
            if ($remaining === []) {
                unset($this->tables[$table]['indexes'][$index]);
            } else {
                $this->tables[$table]['indexes'][$index]['columns'] = $remaining;
            }
        }

        foreach ($this->tables[$table]['foreign'] as $fk => $definition) {
            if ($definition['column'] === $column) {
                unset($this->tables[$table]['foreign'][$fk]);
            }
        }
    }

    private function alterDefault(string $table, string $column, ?string $default): void
    {
        if (!isset($this->tables[$table]['columns'][$column])) {
            throw new RuntimeException("ALTER COLUMN {$table}.{$column}: unknown column");
        }

        $definition = $this->tables[$table]['columns'][$column];
        $masked = self::mask($definition);

        // Remove an existing DEFAULT clause (value is one token or one quoted string).
        if (preg_match('/\sDEFAULT\s+(\S+)/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $length = strlen($m[0][0]);
            $definition = substr($definition, 0, $start) . substr($definition, $start + $length);
        }

        if ($default !== null) {
            $definition .= ' DEFAULT ' . $default;
        }

        $this->tables[$table]['columns'][$column] = self::normaliseSpace($definition);
    }

    /**
     * @param array{primary: bool, unique: bool, position: ?string} $inline
     */
    private function applyInlineKeys(string $table, string $column, array $inline): void
    {
        if ($inline['primary']) {
            if ($this->tables[$table]['primary'] !== [] && $this->tables[$table]['primary'] !== [$column]) {
                throw new RuntimeException("{$table}: multiple primary key defined");
            }
            $this->tables[$table]['primary'] = [$column];
        }

        if ($inline['unique'] && !$this->hasIndexStartingWith($table, $column, true)) {
            $this->addIndex($table, $column, $column, true, false);
        }
    }

    // --------------------------------------------------------------- indexes ---

    private function addIndex(string $table, string $name, string $columnList, bool $unique, bool $fulltext): void
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException("index on {$table}: table does not exist");
        }

        $columns = array_map(static fn (string $c): string => self::normaliseSpace(str_replace('`', '', trim($c))), self::splitTopLevel($columnList));

        foreach ($columns as $spec) {
            $column = self::columnOf($spec);
            if (!isset($this->tables[$table]['columns'][$column])) {
                throw new RuntimeException("index on {$table}: key column '{$column}' doesn't exist");
            }
        }

        if ($name === '') {
            // MySQL names an unnamed index after its first column, suffixed if taken.
            $base = self::columnOf($columns[0]);
            $name = $base;
            for ($n = 2; isset($this->tables[$table]['indexes'][$name]); $n++) {
                $name = $base . '_' . $n;
            }
        }

        if (isset($this->tables[$table]['indexes'][$name]) || $name === 'primary') {
            throw new RuntimeException("index {$table}.{$name}: duplicate key name");
        }

        $this->tables[$table]['indexes'][$name] = ['unique' => $unique, 'fulltext' => $fulltext, 'columns' => array_values($columns)];
    }

    private function dropIndex(string $table, string $name): void
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException("DROP INDEX on {$table}: table does not exist");
        }
        if ($name === 'primary') {
            $this->tables[$table]['primary'] = [];

            return;
        }
        if (!isset($this->tables[$table]['indexes'][$name])) {
            throw new RuntimeException("DROP INDEX {$table}.{$name}: can't DROP; check that it exists");
        }

        unset($this->tables[$table]['indexes'][$name]);
    }

    private function hasIndexStartingWith(string $table, string $column, bool $uniqueOnly = false): bool
    {
        if (!$uniqueOnly && ($this->tables[$table]['primary'][0] ?? null) === $column) {
            return true;
        }

        foreach ($this->tables[$table]['indexes'] as $definition) {
            if ($uniqueOnly && !$definition['unique']) {
                continue;
            }
            if (self::columnOf($definition['columns'][0] ?? '') === $column && (!$uniqueOnly || count($definition['columns']) === 1)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------- foreign keys ---

    private function addForeignKeyClause(string $table, string $clause): void
    {
        $pattern = '/^(?:CONSTRAINT\s*`?(\w+)?`?\s+)?FOREIGN\s+KEY\s*`?(\w+)?`?\s*\(\s*`?(\w+)`?\s*\)\s*REFERENCES\s+`?(\w+)`?\s*\(\s*`?(\w+)`?\s*\)(.*)$/is';

        if (!preg_match($pattern, trim($clause), $m)) {
            throw new RuntimeException("cannot read foreign key clause on {$table} [{$clause}] (only single-column keys are supported)");
        }

        $column = strtolower($m[3]);
        $references = strtolower($m[4]);
        $referencedColumn = strtolower($m[5]);
        $rest = $m[6];

        if (!isset($this->tables[$table]['columns'][$column])) {
            throw new RuntimeException("foreign key on {$table}: key column '{$column}' doesn't exist");
        }
        if (!isset($this->tables[$references])) {
            throw new RuntimeException("foreign key {$table}.{$column}: referenced table '{$references}' doesn't exist");
        }
        if (!isset($this->tables[$references]['columns'][$referencedColumn])) {
            throw new RuntimeException("foreign key {$table}.{$column}: referenced column {$references}.{$referencedColumn} doesn't exist");
        }

        $mine = self::columnType($this->tables[$table]['columns'][$column]);
        $theirs = self::columnType($this->tables[$references]['columns'][$referencedColumn]);
        if (self::comparableType($mine) !== self::comparableType($theirs)) {
            throw new RuntimeException("foreign key {$table}.{$column} ({$mine}) -> {$references}.{$referencedColumn} ({$theirs}): incompatible types (errno 150)");
        }

        $name = strtolower(($m[1] ?? '') !== '' ? $m[1] : '');
        if ($name === '') {
            $n = 1;
            do {
                $name = $table . '_ibfk_' . $n++;
            } while (isset($this->tables[$table]['foreign'][$name]));
        }

        if (isset($this->tables[$table]['foreign'][$name])) {
            throw new RuntimeException("foreign key {$table}.{$name}: duplicate constraint name");
        }

        $onDelete = preg_match('/ON\s+DELETE\s+(SET\s+NULL|CASCADE|RESTRICT|NO\s+ACTION|SET\s+DEFAULT)/i', $rest, $d) ? strtoupper(self::normaliseSpace($d[1])) : null;
        $onUpdate = preg_match('/ON\s+UPDATE\s+(SET\s+NULL|CASCADE|RESTRICT|NO\s+ACTION|SET\s+DEFAULT)/i', $rest, $u) ? strtoupper(self::normaliseSpace($u[1])) : null;

        $this->tables[$table]['foreign'][$name] = [
            'column' => $column,
            'references' => $references,
            'referenced_column' => $referencedColumn,
            'on_delete' => $onDelete,
            'on_update' => $onUpdate,
        ];

        // MySQL adds an index for the key unless one already starts with its column.
        if (!$this->hasIndexStartingWith($table, $column)) {
            $indexName = strtolower(($m[2] ?? '') !== '' ? $m[2] : $name);
            $this->tables[$table]['indexes'][$indexName] = ['unique' => false, 'fulltext' => false, 'columns' => [$column]];
        }
    }

    private function renameTable(string $from, string $to): void
    {
        if (!isset($this->tables[$from])) {
            throw new RuntimeException("RENAME TABLE {$from}: table does not exist");
        }
        if (isset($this->tables[$to])) {
            throw new RuntimeException("RENAME TABLE {$from} TO {$to}: target already exists");
        }

        $this->tables[$to] = $this->tables[$from];
        unset($this->tables[$from]);

        foreach ($this->tables as $table => $schema) {
            foreach ($schema['foreign'] as $fk => $definition) {
                if ($definition['references'] === $from) {
                    $this->tables[$table]['foreign'][$fk]['references'] = $to;
                }
            }
        }
    }

    // ------------------------------------------------------------------- DML ---

    private function checkDml(string $sql): void
    {
        if (preg_match('/^(?:INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?(?:INTO\s+)?`?(\w+)`?\s*(\(([^)]*)\))?/i', $sql, $m)) {
            $table = strtolower($m[1]);
            $this->requireTable($table);

            if (($m[3] ?? '') !== '') {
                foreach (explode(',', $m[3]) as $column) {
                    $column = strtolower(trim($column, " `\t\n"));
                    if ($column !== '' && !isset($this->tables[$table]['columns'][$column])) {
                        throw new RuntimeException("INSERT INTO {$table}: unknown column '{$column}'");
                    }
                }
            }

            return;
        }

        if (preg_match('/^UPDATE\s+(?:LOW_PRIORITY\s+)?(?:IGNORE\s+)?`?(\w+)`?/i', $sql, $m)) {
            $this->requireTable(strtolower($m[1]));

            return;
        }

        if (preg_match('/^DELETE\s+(?:\w+\s+)?FROM\s+`?(\w+)`?/i', $sql, $m)) {
            $this->requireTable(strtolower($m[1]));

            return;
        }

        if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?/i', $sql, $m)) {
            $this->requireTable(strtolower($m[1]));
        }
    }

    public function requireTable(string $table): void
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException("table '{$table}' doesn't exist");
        }
    }

    // ---------------------------------------------------- INFORMATION_SCHEMA ---

    /**
     * Answer a SELECT against INFORMATION_SCHEMA from the model, or null when the
     * query is not one. Supports the shapes migrations use: equality / IN conditions
     * joined by AND, a field list (with aliases, literals and COUNT(*)), ORDER BY and
     * LIMIT are accepted and LIMIT is honoured.
     *
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>|null
     */
    public function informationSchema(string $sql, array $bindings): ?array
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', self::stripComments($sql)));

        if (!preg_match('/^SELECT\s+(DISTINCT\s+)?(.*?)\s+FROM\s+INFORMATION_SCHEMA\.(\w+)(?:\s+(?:AS\s+)?(\w+))?(?:\s+WHERE\s+(.*?))?(?:\s+GROUP\s+BY\s+(\w+))?(?:\s+ORDER\s+BY\s+.*?)?(?:\s+LIMIT\s+(\d+))?$/i', $flat, $m)) {
            return null;
        }

        $distinct = trim($m[1]) !== '';
        $fields = $m[2];
        $view = strtoupper($m[3]);
        $alias = isset($m[4]) && $m[4] !== '' && !in_array(strtoupper($m[4]), ['WHERE', 'ORDER', 'LIMIT'], true) ? $m[4] : null;
        $where = $m[5] ?? '';
        $groupBy = isset($m[6]) && $m[6] !== '' ? strtoupper($m[6]) : null;
        $limit = isset($m[7]) && $m[7] !== '' ? (int) $m[7] : null;

        $rows = match ($view) {
            'COLUMNS' => $this->columnRows(),
            'STATISTICS' => $this->statisticsRows(),
            'TABLE_CONSTRAINTS' => $this->constraintRows(),
            'KEY_COLUMN_USAGE' => $this->keyColumnRows(),
            'TABLES' => $this->tableRows(),
            default => throw new RuntimeException("INFORMATION_SCHEMA.{$view} is not modelled"),
        };

        // WHERE: AND-joined conditions; placeholders consumed left to right.
        $bindingIndex = 0;
        if ($where !== '') {
            foreach (preg_split('/\s+AND\s+/i', $where) ?: [] as $condition) {
                $condition = trim($condition);
                while (str_starts_with($condition, '(') && str_ends_with($condition, ')') && self::splitTopLevel(substr($condition, 1, -1), "\0") !== [] && self::mask($condition) === '(' . str_repeat('_', strlen($condition) - 2) . ')') {
                    $condition = trim(substr($condition, 1, -1));
                }
                if ($alias !== null) {
                    $condition = (string) preg_replace('/^' . preg_quote($alias, '/') . '\./i', '', $condition);
                }

                if (preg_match('/^(\w+)\s*=\s*DATABASE\(\s*\)$/i', $condition)) {
                    continue;
                }

                if (preg_match('/^(\w+)\s*(=|<>|!=)\s*(\?|\'[^\']*\'|"[^"]*"|\d+)$/', $condition, $c)) {
                    $value = $c[3] === '?' ? ($bindings[$bindingIndex++] ?? null) : trim($c[3], '\'"');
                    $field = strtoupper($c[1]);
                    $negate = $c[2] !== '=';
                    $rows = array_values(array_filter($rows, static fn (array $row): bool => (strcasecmp((string) ($row[$field] ?? ''), (string) $value) === 0) !== $negate));
                    continue;
                }

                if (preg_match('/^(\w+)\s+(NOT\s+)?IN\s*\((.*)$/i', $condition, $c)) {
                    $values = [];
                    foreach (explode(',', rtrim($c[3], ') ')) as $item) {
                        $item = trim($item);
                        $values[] = strtolower($item === '?' ? (string) ($bindings[$bindingIndex++] ?? '') : trim($item, '\'"'));
                    }
                    $field = strtoupper($c[1]);
                    $negate = trim($c[2] ?? '') !== '';
                    $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array(strtolower((string) ($row[$field] ?? '')), $values, true) !== $negate));
                    continue;
                }

                throw new RuntimeException("cannot evaluate INFORMATION_SCHEMA condition [{$condition}]");
            }
        }

        // Projection. Aggregates (COUNT/MAX/MIN) collapse a group — the whole result
        // without GROUP BY — into one row, as SQL does.
        $fieldList = array_map('trim', self::splitTopLevel($fields));
        $aggregate = false;
        foreach ($fieldList as $field) {
            if (preg_match('/^(COUNT|MAX|MIN)\s*\(/i', $field)) {
                $aggregate = true;
            }
        }

        if ($groupBy !== null || $aggregate) {
            $groups = [];
            foreach ($rows as $row) {
                $groups[$groupBy !== null ? strtolower((string) ($row[$groupBy] ?? '')) : ''][] = $row;
            }
            if ($groups === [] && $groupBy === null) {
                $groups[''] = [];
            }
            $projected = [];
            foreach ($groups as $group) {
                $projected[] = self::projectRow($fieldList, $group[0] ?? [], $group);
            }
        } else {
            $projected = array_map(static fn (array $row): array => self::projectRow($fieldList, $row, [$row]), $rows);
        }

        if ($distinct) {
            $projected = array_values(array_unique($projected, SORT_REGULAR));
        }

        return $limit !== null ? array_slice($projected, 0, $limit) : $projected;
    }

    /**
     * @param array<int, string> $fieldList
     * @param array<string, mixed> $row the representative row (first of the group)
     * @param array<int, array<string, mixed>> $group
     * @return array<string, mixed>
     */
    private static function projectRow(array $fieldList, array $row, array $group): array
    {
        $out = [];
        foreach ($fieldList as $field) {
            if ($field === '*') {
                $out += $row;
                continue;
            }
            if (preg_match('/^(COUNT|MAX|MIN)\s*\(\s*(\*|1|\w+)\s*\)(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i', $field, $a)) {
                $key = isset($a[3]) && $a[3] !== '' ? $a[3] : $field;
                $function = strtoupper($a[1]);
                if ($function === 'COUNT') {
                    $out[$key] = count($group);
                    continue;
                }
                $values = array_map(static fn (array $r): mixed => $r[strtoupper($a[2])] ?? null, $group);
                $out[$key] = $values === [] ? null : ($function === 'MAX' ? max($values) : min($values));
                continue;
            }
            if (!preg_match('/^(?:\w+\.)?(\w+|\d+|\'[^\']*\')(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i', $field, $f)) {
                throw new RuntimeException("cannot project INFORMATION_SCHEMA field [{$field}]");
            }
            $source = $f[1];
            $key = isset($f[2]) && $f[2] !== '' ? $f[2] : (preg_replace('/^\w+\./', '', $field) ?? $field);
            $out[$key] = ctype_digit($source) ? (int) $source : (str_starts_with($source, "'") ? trim($source, "'") : ($row[strtoupper($source)] ?? null));
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function columnRows(): array
    {
        $rows = [];
        foreach ($this->tables as $table => $schema) {
            $position = 0;
            foreach ($schema['columns'] as $column => $definition) {
                $type = self::columnType($definition);
                $masked = self::mask($definition);
                $nullable = !preg_match('/\bNOT\s+NULL\b/i', $masked) && !in_array($column, $schema['primary'], true);
                $rows[] = [
                    'TABLE_SCHEMA' => 'codevault',
                    'TABLE_NAME' => $table,
                    'COLUMN_NAME' => $column,
                    'ORDINAL_POSITION' => ++$position,
                    'COLUMN_DEFAULT' => self::defaultOf($definition),
                    'IS_NULLABLE' => $nullable ? 'YES' : 'NO',
                    'DATA_TYPE' => strtolower((string) preg_replace('/[\s(].*$/s', '', $type)),
                    'COLUMN_TYPE' => self::lowerOutsideQuotes($type),
                    'COLUMN_KEY' => in_array($column, $schema['primary'], true) ? 'PRI' : '',
                    'EXTRA' => preg_match('/\bAUTO_INCREMENT\b/i', $masked) ? 'auto_increment' : (preg_match('/\bAS\s*\(/i', $masked) ? 'STORED GENERATED' : ''),
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function statisticsRows(): array
    {
        $rows = [];
        foreach ($this->tables as $table => $schema) {
            foreach ($schema['primary'] as $i => $column) {
                $rows[] = ['TABLE_SCHEMA' => 'codevault', 'TABLE_NAME' => $table, 'INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => $column, 'SEQ_IN_INDEX' => $i + 1, 'NON_UNIQUE' => 0, 'INDEX_TYPE' => 'BTREE'];
            }
            foreach ($schema['indexes'] as $index => $definition) {
                foreach ($definition['columns'] as $i => $spec) {
                    $rows[] = ['TABLE_SCHEMA' => 'codevault', 'TABLE_NAME' => $table, 'INDEX_NAME' => $index, 'COLUMN_NAME' => self::columnOf($spec), 'SEQ_IN_INDEX' => $i + 1, 'NON_UNIQUE' => $definition['unique'] ? 0 : 1, 'INDEX_TYPE' => $definition['fulltext'] ? 'FULLTEXT' : 'BTREE'];
                }
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function constraintRows(): array
    {
        $rows = [];
        foreach ($this->tables as $table => $schema) {
            $base = ['CONSTRAINT_SCHEMA' => 'codevault', 'TABLE_SCHEMA' => 'codevault', 'TABLE_NAME' => $table];
            if ($schema['primary'] !== []) {
                $rows[] = $base + ['CONSTRAINT_NAME' => 'PRIMARY', 'CONSTRAINT_TYPE' => 'PRIMARY KEY'];
            }
            foreach ($schema['indexes'] as $index => $definition) {
                if ($definition['unique']) {
                    $rows[] = $base + ['CONSTRAINT_NAME' => $index, 'CONSTRAINT_TYPE' => 'UNIQUE'];
                }
            }
            foreach (array_keys($schema['foreign']) as $fk) {
                $rows[] = $base + ['CONSTRAINT_NAME' => $fk, 'CONSTRAINT_TYPE' => 'FOREIGN KEY'];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function keyColumnRows(): array
    {
        $rows = [];
        foreach ($this->tables as $table => $schema) {
            foreach ($schema['foreign'] as $fk => $definition) {
                $rows[] = [
                    'CONSTRAINT_SCHEMA' => 'codevault', 'TABLE_SCHEMA' => 'codevault', 'TABLE_NAME' => $table,
                    'CONSTRAINT_NAME' => $fk, 'COLUMN_NAME' => $definition['column'],
                    'REFERENCED_TABLE_NAME' => $definition['references'], 'REFERENCED_COLUMN_NAME' => $definition['referenced_column'],
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function tableRows(): array
    {
        $rows = [];
        foreach (array_keys($this->tables) as $table) {
            $rows[] = ['TABLE_SCHEMA' => 'codevault', 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB'];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- export ---

    /**
     * The model as plain data, sorted by table name so a regenerated snapshot only
     * differs where the schema does.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $tables = $this->tables;
        ksort($tables);

        return $tables;
    }

    // --------------------------------------------------------------- parsing ---

    /**
     * A column clause split into its name, its definition (without inline keys or
     * position), and the inline keys/position it carried.
     *
     * @return array{0: string, 1: string, 2: array{primary: bool, unique: bool, position: ?string}}
     */
    private static function parseColumn(string $text): array
    {
        $text = trim($text);

        if (!preg_match('/^`?(\w+)`?\s+(.+)$/s', $text, $m)) {
            throw new RuntimeException("cannot read column definition [{$text}]");
        }

        $name = strtolower($m[1]);
        $definition = $m[2];
        $inline = ['primary' => false, 'unique' => false, 'position' => null];

        // Cut clauses found at depth 0 outside quotes, last to first so offsets hold.
        $cuts = [];
        $masked = self::mask($definition);

        if (preg_match('/\s(FIRST|AFTER\s+`?(\w+)`?)\s*$/i', $masked, $p, PREG_OFFSET_CAPTURE)) {
            $inline['position'] = strtoupper($p[1][0]) === 'FIRST' ? 'FIRST' : strtolower($p[2][0]);
            $cuts[] = [$p[0][1], strlen($p[0][0])];
        }
        if (preg_match('/\sREFERENCES\s.*$/is', $masked, $r, PREG_OFFSET_CAPTURE)) {
            // An inline REFERENCES is parsed and IGNORED by MySQL: no constraint results.
            $cuts[] = [$r[0][1], strlen($r[0][0])];
        }
        if (preg_match('/\sPRIMARY\s+KEY\b/i', $masked, $k, PREG_OFFSET_CAPTURE)) {
            $inline['primary'] = true;
            $cuts[] = [$k[0][1], strlen($k[0][0])];
        }
        if (preg_match('/\sUNIQUE(\s+KEY)?\b/i', $masked, $u, PREG_OFFSET_CAPTURE)) {
            $inline['unique'] = true;
            $cuts[] = [$u[0][1], strlen($u[0][0])];
        }

        usort($cuts, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $seen = [];
        foreach ($cuts as [$offset, $length]) {
            if (isset($seen[$offset])) {
                continue;
            }
            $seen[$offset] = true;
            $definition = substr($definition, 0, $offset) . ' ' . substr($definition, $offset + $length);
        }

        return [$name, self::normaliseSpace($definition), $inline];
    }

    /** The type part of a definition: base type, its (args), UNSIGNED / ZEROFILL. */
    public static function columnType(string $definition): string
    {
        $definition = trim($definition);
        if (!preg_match('/^(\w+)/', $definition, $m)) {
            return '';
        }
        $type = strtoupper($m[1]);
        $rest = substr($definition, strlen($m[1]));

        if (preg_match('/^\s*\(/', $rest)) {
            $depth = 0;
            $quote = null;
            $length = strlen($rest);
            for ($i = 0; $i < $length; $i++) {
                $ch = $rest[$i];
                if ($quote !== null) {
                    if ($ch === '\\') {
                        $i++;
                    } elseif ($ch === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"') {
                    $quote = $ch;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $type .= trim(substr($rest, 0, $i + 1));
                        $rest = substr($rest, $i + 1);
                        break;
                    }
                }
            }
        }

        while (preg_match('/^\s+(UNSIGNED|ZEROFILL|SIGNED)\b/i', $rest, $mm)) {
            $type .= ' ' . strtoupper($mm[1]);
            $rest = substr($rest, strlen($mm[0]));
        }

        return $type;
    }

    /** Integer display widths are cosmetic (and gone in MySQL 8); compare without them. */
    public static function comparableType(string $type): string
    {
        $type = strtoupper(trim($type));
        $type = (string) preg_replace('/^(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\s*\(\d+\)/', '$1', $type);
        $type = (string) preg_replace('/^INTEGER\b/', 'INT', $type);
        $type = str_replace(' SIGNED', '', $type);

        return self::normaliseSpace($type);
    }

    /** @return array<int, string>|null the values of an ENUM/SET type, or null */
    public static function enumValues(string $type): ?array
    {
        if (!preg_match('/^(ENUM|SET)\s*\((.*)\)$/is', trim($type), $m)) {
            return null;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $m[2], $values);

        return array_map(static fn (string $v): string => str_replace("''", "'", $v), $values[1]);
    }

    private static function defaultOf(string $definition): ?string
    {
        $masked = self::mask($definition);
        if (!preg_match('/\sDEFAULT\s+/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $rest = ltrim(substr($definition, $m[0][1] + strlen($m[0][0])));
        if (preg_match("/^'((?:[^'\\\\]|\\\\.|'')*)'/", $rest, $q)) {
            return str_replace("''", "'", $q[1]);
        }
        $token = (string) preg_replace('/[\s,].*$/s', '', $rest);

        return strtoupper($token) === 'NULL' ? null : $token;
    }

    public static function columnOf(string $spec): string
    {
        return strtolower((string) preg_replace('/^`?(\w+)`?.*$/s', '$1', trim($spec)));
    }

    /** @return array<int, string> */
    private static function indexColumnNames(string $list): array
    {
        return array_map(static fn (string $c): string => self::columnOf($c), self::splitTopLevel($list));
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    private static function insertAt(array $columns, string $name, string $definition, ?string $position): array
    {
        if ($position === null) {
            $columns[$name] = $definition;

            return $columns;
        }

        if ($position === 'FIRST') {
            return [$name => $definition] + $columns;
        }

        if (!isset($columns[$position])) {
            throw new RuntimeException("AFTER {$position}: unknown column");
        }

        $result = [];
        foreach ($columns as $column => $existing) {
            $result[$column] = $existing;
            if ($column === $position) {
                $result[$name] = $definition;
            }
        }

        return $result;
    }

    /**
     * Split on commas that are outside quotes and parentheses.
     *
     * @return array<int, string>
     */
    public static function splitTopLevel(string $text, string $separator = ','): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $text[++$i];
                } elseif ($ch === $quote) {
                    if ($i + 1 < $length && $text[$i + 1] === $quote) {
                        $current .= $text[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * The text with everything inside quotes or parentheses replaced by underscores
     * (same length), so keyword searches only see the top level.
     */
    public static function mask(string $text): string
    {
        $out = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                if ($ch === '\\' && $i + 1 < $length) {
                    $out .= '__';
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $length && $text[$i + 1] === $quote) {
                        $out .= '__';
                        $i++;
                        continue;
                    }
                    $quote = null;
                    $out .= $ch;
                    continue;
                }
                $out .= '_';
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= $ch;
            } elseif ($ch === '(') {
                $depth++;
                $out .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $out .= $ch;
            } else {
                $out .= $depth > 0 ? '_' : $ch;
            }
        }

        return $out;
    }

    /** Collapse whitespace runs outside quoted strings. */
    public static function normaliseSpace(string $text): string
    {
        $out = '';
        $quote = null;
        $length = strlen($text);
        $lastSpace = false;

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $out .= $text[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= $ch;
                $lastSpace = false;
                continue;
            }

            if (ctype_space($ch)) {
                if (!$lastSpace) {
                    $out .= ' ';
                }
                $lastSpace = true;
                continue;
            }

            $out .= $ch;
            $lastSpace = false;
        }

        return trim($out);
    }

    private static function lowerOutsideQuotes(string $text): string
    {
        return (string) preg_replace_callback("/('(?:[^'\\\\]|\\\\.|'')*')|([^']+)/", static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : strtolower($m[2]), $text);
    }

    public static function stripComments(string $sql): string
    {
        // Line comments (outside quotes is good enough for migration SQL) and block comments.
        $sql = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);

        return (string) preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    }
}
