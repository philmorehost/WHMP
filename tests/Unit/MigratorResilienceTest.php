<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use RuntimeException;

/**
 * One broken migration must not block every later one.
 *
 * The migrator ran to completion or not at all, so a single failing file left the
 * schema short by itself AND by every migration after it. That is not theoretical:
 * a live install ended up missing several unrelated tables at once — including
 * `reseller_payouts` — each surfacing as its own "Table ... doesn't exist" fatal
 * on a different admin page, while the boot step that could have said why was
 * discarding the exception entirely.
 *
 * The boot path now asks for tolerance (run(true)); bin/migrate.php does NOT, so an
 * operator still gets the error and a non-zero exit. Both halves are pinned here,
 * because "tolerant on boot" is only safe while "strict on demand" is still true.
 */
final class MigratorResilienceTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/codevault-migrations-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_failing_migration_does_not_block_the_ones_after_it(): void
    {
        $this->migration('0001_create_probe_first', "CREATE TABLE IF NOT EXISTS probe_first (id INT PRIMARY KEY) ENGINE=InnoDB");
        // The failure every later migration used to be sacrificed to.
        $this->migration('0002_broken', 'ALTER TABLE probe_first ADD COLUMN nope THIS IS NOT SQL');
        $this->migration('0003_create_probe_second', "CREATE TABLE IF NOT EXISTS probe_second (id INT PRIMARY KEY) ENGINE=InnoDB");

        $migrator = new Migrator($this->db, $this->dir);
        $applied = $migrator->run(true);

        $this->assertSame(
            ['0001_create_probe_first.php', '0003_create_probe_second.php'],
            $applied,
            'the migrations either side of the failure should both have applied'
        );

        // The one that ran: proof the run did not stop at 0002.
        $this->assertTrue($this->tableExists('probe_second'));

        // The cause is recorded, and NOT recorded as applied, so it is retried.
        $failures = $migrator->failures();
        $this->assertArrayHasKey('0002_broken.php', $failures);
        // The database's own message is kept, not a generic "failed" — the point
        // of collecting it is that the cause is readable without a debugger.
        $this->assertNotSame('', $failures['0002_broken.php']);
        $this->assertNotContains('0002_broken.php', $migrator->applied());
    }

    public function test_the_strict_runner_still_throws_so_an_operator_sees_it(): void
    {
        $this->migration('0001_broken', 'ALTER TABLE definitely_missing ADD COLUMN nope INT');

        $migrator = new Migrator($this->db, $this->dir);

        $this->expectException(RuntimeException::class);

        // Default (bin/migrate.php): loud, and non-zero.
        $migrator->run();
    }

    private function migration(string $name, string $sql): void
    {
        file_put_contents(
            $this->dir . '/' . $name . '.php',
            "<?php\nreturn ['up' => [" . var_export($sql, true) . "]];\n"
        );
    }

    private function tableExists(string $table): bool
    {
        return $this->db->selectOne(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) !== null;
    }
}
