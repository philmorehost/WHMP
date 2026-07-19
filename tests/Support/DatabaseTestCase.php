<?php

declare(strict_types=1);

namespace CodeVault\Tests\Support;

use CodeVault\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a real MariaDB connection (migrations,
 * licensing, backup/restore) — these exercise actual SQL rather than
 * mocking the database layer. Connects to `codevault_test` on the local
 * XAMPP instance; skips gracefully if that server isn't reachable so the
 * suite still runs somewhere without MariaDB available.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('127.0.0.1', '3306', 'codevault_test', 'root', '');

        try {
            $this->db->connection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No local test database reachable: ' . $e->getMessage());
        }

        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAllTables();
        }
    }

    private function dropAllTables(): void
    {
        $pdo = $this->db->connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->db->select('SHOW TABLES') as $row) {
            $table = array_values($row)[0];
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
