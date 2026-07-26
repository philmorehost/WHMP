<?php

declare(strict_types=1);

namespace CodeVault\Database;

use CodeVault\Database;
use CodeVault\Database\Migrator;
use Throwable;

final class SchemaHealer
{
    public function __construct(
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly string $basePath
    ) {
    }

    public function heal(): void
    {
        try {
            $this->checkAndCreateMissingTables();
            $this->checkAndCreateMissingColumns();
            $this->checkAndCreateMissingIndexes();
        } catch (Throwable $e) {
            error_log("SchemaHealer error: {$e->getMessage()}");
        }
    }

    private function checkAndCreateMissingTables(): void
    {
        $result = $this->db->select("
            SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
        ");

        $existingTables = array_column($result, 'TABLE_NAME');
        $requiredTables = $this->getRequiredTables();

        foreach ($requiredTables as $table) {
            if (!in_array($table, $existingTables)) {
                error_log("SchemaHealer: Creating missing table: {$table}");
                $this->createTableFromMigration($table);
            }
        }
    }

    private function checkAndCreateMissingColumns(): void
    {
        $requiredColumns = $this->getRequiredColumns();

        foreach ($requiredColumns as $table => $columns) {
            $result = $this->db->select("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
            ", [$table]);

            $existingColumns = array_column($result, 'COLUMN_NAME');

            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existingColumns)) {
                    error_log("SchemaHealer: Adding missing column {$table}.{$column}");
                    try {
                        $this->db->statement("
                            ALTER TABLE {$table} ADD COLUMN {$column} {$definition}
                        ");
                    } catch (Throwable $e) {
                        error_log("SchemaHealer: Failed to add column {$table}.{$column}: {$e->getMessage()}");
                    }
                }
            }
        }
    }

    private function checkAndCreateMissingIndexes(): void
    {
        $requiredIndexes = $this->getRequiredIndexes();

        foreach ($requiredIndexes as $table => $indexes) {
            foreach ($indexes as $indexName => $columns) {
                $result = $this->db->selectOne("
                    SELECT COUNT(*) as count FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND INDEX_NAME = ?
                ", [$table, $indexName]);

                if (($result['count'] ?? 0) === 0) {
                    error_log("SchemaHealer: Creating missing index {$table}.{$indexName}");
                    try {
                        $columnList = implode(', ', $columns);
                        $this->db->statement("
                            ALTER TABLE {$table} ADD INDEX {$indexName} ({$columnList})
                        ");
                    } catch (Throwable $e) {
                        error_log("SchemaHealer: Failed to create index {$table}.{$indexName}: {$e->getMessage()}");
                    }
                }
            }
        }
    }

    private function createTableFromMigration(string $table): void
    {
        $migrationMap = $this->getMigrationTableMap();

        if (isset($migrationMap[$table])) {
            $migrationFile = $migrationMap[$table];
            $filepath = "{$this->basePath}/database/migrations/{$migrationFile}";

            if (is_file($filepath)) {
                $migration = include $filepath;
                if (isset($migration['up'])) {
                    foreach ((array) $migration['up'] as $statement) {
                        if (is_string($statement)) {
                            try {
                                $this->db->statement($statement);
                            } catch (Throwable $e) {
                                error_log("SchemaHealer: Failed to create table {$table}: {$e->getMessage()}");
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Map of critical tables to their defining migration files
     */
    private function getMigrationTableMap(): array
    {
        return [
            'admins' => '0001_create_admins_table.php',
            'settings' => '0002_create_settings_table.php',
            'roles' => '0003_create_roles_table.php',
            'permissions' => '0004_create_permissions_table.php',
            'clients' => '0005_create_clients_table.php',
            'invoices' => '0008_create_invoices_table.php',
            'email_templates' => '0016_create_email_templates_table.php',
            'migrations' => '0001_create_migrations_table.php',
            'products' => '0021_create_products_table.php',
            'product_groups' => '0020_create_product_groups_table.php',
            'services' => '0009_create_services_table.php',
            'domains' => '0025_create_domains_table.php',
            'tickets' => '0012_create_tickets_table.php',
            'orders' => '0007_create_orders_table.php',
            'activity_log' => '0014_create_activity_log_table.php',
            'email_log' => '0017_create_email_log_table.php',
        ];
    }

    /**
     * Returns list of critical tables that must exist
     */
    private function getRequiredTables(): array
    {
        return array_keys($this->getMigrationTableMap());
    }

    /**
     * Returns map of table_name => [column_name => column_definition]
     * Only for commonly-added columns that should exist
     */
    private function getRequiredColumns(): array
    {
        return [
            'invoices' => [
                'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ],
            'clients' => [
                'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ],
            'services' => [
                'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ],
            'products' => [
                'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ],
        ];
    }

    /**
     * Returns map of table_name => [index_name => [columns]]
     */
    private function getRequiredIndexes(): array
    {
        return [
            'invoices' => [
                'idx_client_id' => ['client_id'],
                'idx_created_at' => ['created_at'],
            ],
            'activity_log' => [
                'idx_actor' => ['actor_type', 'actor_id'],
                'idx_subject' => ['subject_type', 'subject_id'],
                'idx_created' => ['created_at'],
            ],
            'email_log' => [
                'idx_client_id' => ['client_id'],
                'idx_status' => ['status'],
            ],
        ];
    }
}
