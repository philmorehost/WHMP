<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    public function test_run_applies_the_real_r1_migrations_and_is_idempotent(): void
    {
        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');

        $firstRun = $migrator->run();

        $this->assertContains('0001_create_admins_table.php', $firstRun);
        $this->assertContains('0002_create_settings_table.php', $firstRun);
        $this->assertContains('0003_create_license_activation_table.php', $firstRun);

        $tables = array_map(fn (array $row) => array_values($row)[0], $this->db->select('SHOW TABLES'));
        $this->assertContains('admins', $tables);
        $this->assertContains('settings', $tables);
        // license_activation (0003) is renamed to system_activation by a
        // later migration (0095) as part of scrubbing "license" from every
        // traceable identifier — by the time run() finishes, only the
        // renamed table exists.
        $this->assertContains('system_activation', $tables);

        // Running again must be a no-op — nothing pending, nothing re-applied.
        $secondRun = $migrator->run();
        $this->assertSame([], $secondRun);
        $this->assertSame([], $migrator->pending());
    }

    public function test_applied_reflects_what_has_run(): void
    {
        $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->run();

        $this->assertContains('0001_create_admins_table.php', $migrator->applied());
    }
}
