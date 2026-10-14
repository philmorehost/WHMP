<?php

declare(strict_types=1);

namespace CodeVault\Tests\Support;

use CodeVault\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a real MariaDB connection (migrations,
 * licensing, backup/restore) — these exercise actual SQL rather than
 * mocking the database layer.
 *
 * The connection comes from {@see TestDatabase}, so CI, Docker Compose and a
 * local checkout all configure it the same way (`DB_HOST`, `DB_PORT`,
 * `DB_USERNAME`, `DB_PASSWORD`, `DB_TEST_DATABASE`). Every table is dropped
 * before and after each test, so tests never inherit another run's leftovers —
 * including the `migrations` bookkeeping table (see the note in
 * {@see self::dropAllTables()}).
 *
 * When no server is reachable the tests skip instead of failing, which keeps the
 * suite runnable on a machine with no MariaDB at all. The decision is made in
 * setUpBeforeClass() rather than setUp(): PHPUnit still calls tearDown() after a
 * test was skipped in setUp(), and a subclass tearDown that touches state its
 * setUp never created would fail — turning "no database here, skip" into an
 * error. Skipping the whole class before any of its setUp() runs avoids that
 * trap for every subclass.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        // Never open a connection we have been told not to open, and never point
        // destructive tests at a schema that is not clearly a throwaway one.
        if (($reason = TestDatabase::skipReason()) !== null) {
            self::markTestSkipped($reason);
        }

        try {
            TestDatabase::connection()->connection();
        } catch (\Throwable $e) {
            self::markTestSkipped('No test database reachable at ' . TestDatabase::dsn() . ': ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->db = TestDatabase::connection();
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

        // `migrations` goes FIRST, deliberately. Everything else here is
        // order-independent; this one row is not.
        //
        // It records which migrations have been applied, so a drop interrupted
        // partway through (killed run, cancelled debug session) can otherwise
        // leave rows claiming work whose tables no longer exist. The NEXT run
        // then dies in the middle of the chain — on an `ALTER TABLE` against a
        // table that was never created, which reads exactly like a
        // migration-ordering bug — when the real state is "the bookkeeping
        // outlived the schema". That is a genuinely confusing failure: it cost
        // real time to diagnose, and it is not a property of any migration.
        //
        // Dropping it first makes the state recoverable by construction: with no
        // `migrations` table, the next migrate() simply re-runs everything.
        $pdo->exec('DROP TABLE IF EXISTS `migrations`');

        foreach ($this->db->select('SHOW TABLES') as $row) {
            $table = array_values($row)[0];
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
