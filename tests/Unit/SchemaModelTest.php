<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Schema\SimulatedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The simulator database/schema.php is built with. It has to agree with MySQL on
 * what each statement does, and on what INFORMATION_SCHEMA answers to the guarded
 * migrations ("add this only if it is missing"), or the snapshot would be wrong.
 */
final class SchemaModelTest extends TestCase
{
    private function db(): SimulatedDatabase
    {
        $db = new SimulatedDatabase();
        $db->statement('CREATE TABLE owners (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(191) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->statement(<<<'SQL'
            CREATE TABLE pets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_id INT UNSIGNED NOT NULL,
                kind ENUM('cat','dog') NOT NULL DEFAULT 'cat',
                name VARCHAR(100) NOT NULL,
                CONSTRAINT fk_pets_owner FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        return $db;
    }

    public function test_create_table_records_columns_keys_and_the_implicit_foreign_key_index(): void
    {
        $db = $this->db();
        $pets = $db->model->tables['pets'];

        $this->assertSame(['id', 'owner_id', 'kind', 'name'], array_keys($pets['columns']));
        $this->assertSame(['id'], $pets['primary']);
        $this->assertSame(['owner_id'], $pets['indexes']['fk_pets_owner']['columns'], 'InnoDB adds an index for an unindexed FK column');
        $this->assertSame('owners', $pets['foreign']['fk_pets_owner']['references']);
        $this->assertTrue($db->model->tables['owners']['indexes']['email']['unique']);
        $this->assertSame([], $db->model->errors);
    }

    public function test_alter_table_positions_modifies_and_drops(): void
    {
        $db = $this->db();
        $db->statement("ALTER TABLE pets ADD COLUMN born DATE NULL AFTER kind, MODIFY kind ENUM('cat','dog','bird') NOT NULL DEFAULT 'cat', ADD INDEX idx_pets_name (name)");
        $db->statement('ALTER TABLE pets DROP COLUMN name');

        $pets = $db->model->tables['pets'];
        $this->assertSame(['id', 'owner_id', 'kind', 'born'], array_keys($pets['columns']));
        $this->assertSame("ENUM('cat','dog','bird') NOT NULL DEFAULT 'cat'", $pets['columns']['kind']);
        $this->assertArrayNotHasKey('idx_pets_name', $pets['indexes'], 'dropping the only column of an index drops the index');
        $this->assertSame([], $db->model->errors);
    }

    public function test_rename_table_carries_the_foreign_keys_pointing_at_it(): void
    {
        $db = $this->db();
        $db->statement('RENAME TABLE owners TO people');

        $this->assertArrayNotHasKey('owners', $db->model->tables);
        $this->assertSame('people', $db->model->tables['pets']['foreign']['fk_pets_owner']['references']);
    }

    public function test_it_answers_the_information_schema_questions_guarded_migrations_ask(): void
    {
        $db = $this->db();

        $this->assertSame(1, (int) $db->selectOne(
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['pets', 'kind']
        )['c']);
        $this->assertNull($db->selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pets' AND COLUMN_NAME = 'colour'"
        ));
        $this->assertSame("enum('cat','dog')", $db->selectOne(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pets' AND COLUMN_NAME = 'kind'"
        )['COLUMN_TYPE']);
        $this->assertNotNull($db->selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pets' AND CONSTRAINT_NAME = 'fk_pets_owner' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        ));
        $this->assertNotNull($db->selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'owners'"
        ));
        $this->assertNull($db->selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cages'"
        ));

        $grouped = $db->select(
            "SELECT INDEX_NAME, COUNT(*) AS n FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pets' GROUP BY INDEX_NAME ORDER BY INDEX_NAME"
        );
        $this->assertContains(['INDEX_NAME' => 'PRIMARY', 'n' => 1], $grouped);
        $this->assertContains(['INDEX_NAME' => 'fk_pets_owner', 'n' => 1], $grouped);
    }

    public function test_it_rejects_what_a_server_would_reject(): void
    {
        $db = $this->db();
        $db->statement('ALTER TABLE pets ADD COLUMN name VARCHAR(10)');                      // duplicate column
        $db->statement('CREATE TABLE owners (id INT)');                                       // table exists
        $db->statement('ALTER TABLE cages ADD COLUMN x INT');                                 // no such table
        $db->statement('ALTER TABLE pets ADD CONSTRAINT fk_bad FOREIGN KEY (name) REFERENCES owners(id)'); // type mismatch
        $db->select('SELECT id FROM cages');                                                  // reading a missing table

        $this->assertCount(5, $db->model->errors, implode("\n", $db->model->errors));
    }
}
