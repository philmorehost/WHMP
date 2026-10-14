<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database;
use CodeVault\Database\Schema\MigrationReplay;
use CodeVault\Database\Schema\SchemaReconciler;
use CodeVault\Database\Schema\SimulatedDatabase;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * SchemaReconciler is what brings an already-running live site up to the schema the
 * code expects. Each scenario is a real way a live database ends up behind.
 */
final class SchemaReconcilerTest extends TestCase
{
    private static ?string $migrated = null;

    private string $base;
    private string $snapshot;
    private string $scratch;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__, 2);
        $this->snapshot = $this->base . '/database/schema.php';
        $this->scratch = sys_get_temp_dir() . '/schema-reconciler-' . bin2hex(random_bytes(4));
        @mkdir($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratch);
    }

    /** A database every migration ran against, as on an up-to-date site. */
    private function migrated(): SimulatedDatabase
    {
        // Replaying 200+ migrations is the slow part; replay once, clone the model.
        if (self::$migrated === null) {
            self::$migrated = serialize((new MigrationReplay($this->base . '/database/migrations'))->run()->model);
        }

        return new SimulatedDatabase(unserialize(self::$migrated));
    }

    /** @return array<string, array> tables with index/FK order made irrelevant */
    private static function normalise(array $tables): array
    {
        ksort($tables);
        foreach ($tables as &$table) {
            ksort($table['indexes']);
            ksort($table['foreign']);
        }

        return $tables;
    }

    private function expected(): array
    {
        return self::normalise((require $this->snapshot)['tables']);
    }

    public function test_a_fully_migrated_database_needs_nothing(): void
    {
        $this->assertSame([], (new SchemaReconciler($this->migrated(), $this->snapshot))->plan());
    }

    public function test_an_empty_database_is_built_exactly_to_the_snapshot(): void
    {
        $db = new SimulatedDatabase();
        $result = (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $result['failed']);
        $this->assertSame([], $db->model->errors, 'every generated statement must be valid DDL');
        $this->assertSame($this->expected(), self::normalise($db->model->export()));
        $this->assertSame([], (new SchemaReconciler($db, $this->snapshot))->plan());
    }

    public function test_an_early_install_gets_the_columns_a_rewritten_migration_never_gave_it(): void
    {
        // The 0097/0117 case: 0117 was rewritten to a no-op after the grace/redemption
        // columns were folded into 0097 — a site that had run the ORIGINAL 0097 got
        // them from neither.
        $db = $this->migrated();
        $db->statement('ALTER TABLE domain_pricing DROP COLUMN grace_period_days, DROP COLUMN redemption_period_days, DROP COLUMN redemption_fee');

        $plan = (new SchemaReconciler($db, $this->snapshot))->plan();
        $this->assertSame(
            ['grace_period_days', 'redemption_period_days', 'redemption_fee'],
            array_column(array_filter($plan, static fn (array $s): bool => $s['kind'] === 'column'), 'name')
        );

        $result = (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $result['failed']);
        $this->assertSame([], $db->model->errors);
        $this->assertSame($this->expected(), self::normalise($db->model->export()), 'columns land in their original position, with their original definition');
    }

    public function test_a_table_the_site_never_got_is_created_with_its_foreign_keys(): void
    {
        $db = $this->migrated();
        $db->statement('DROP TABLE client_migrations');

        $plan = (new SchemaReconciler($db, $this->snapshot))->plan();
        $this->assertSame(['table', 'foreign', 'foreign', 'foreign', 'foreign'], array_column($plan, 'kind'));

        (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $db->model->errors);
        $this->assertSame($this->expected(), self::normalise($db->model->export()));
    }

    public function test_a_missing_enum_value_is_added_and_the_existing_values_kept(): void
    {
        $db = $this->migrated();
        $db->statement("ALTER TABLE resellers MODIFY domain_status ENUM('none','pending') NOT NULL DEFAULT 'none'");

        $plan = (new SchemaReconciler($db, $this->snapshot))->plan();
        $this->assertCount(1, $plan);
        $this->assertSame('enum', $plan[0]['kind']);
        $this->assertNotNull($plan[0]['sql']);

        (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame(
            "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
            $db->model->tables['resellers']['columns']['domain_status']
        );
    }

    public function test_an_enum_the_site_customised_is_reported_not_rewritten(): void
    {
        // It lacks values the code writes, but rewriting it to the snapshot's list
        // would drop 'suspended', and MySQL would blank every row holding it. That
        // is a decision for a person.
        $db = $this->migrated();
        $custom = "ENUM('none','pending','suspended') NOT NULL DEFAULT 'none'";
        $db->statement("ALTER TABLE resellers MODIFY domain_status {$custom}");

        $result = (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $result['applied']);
        $this->assertCount(1, $result['reported']);
        $this->assertStringContainsString('resellers.domain_status', $result['reported'][0]);
        $this->assertSame($custom, $db->model->tables['resellers']['columns']['domain_status']);
    }

    public function test_an_enum_with_extra_values_and_nothing_missing_is_left_alone(): void
    {
        $db = $this->migrated();
        $db->statement("ALTER TABLE resellers MODIFY domain_status ENUM('none','pending','approved','rejected','suspended') NOT NULL DEFAULT 'none'");

        $this->assertSame([], (new SchemaReconciler($db, $this->snapshot))->plan());
    }

    public function test_a_missing_index_is_added(): void
    {
        $db = $this->migrated();
        $db->statement('ALTER TABLE promotions DROP INDEX idx_promotions_code');

        (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame(['code'], $db->model->tables['promotions']['indexes']['idx_promotions_code']['columns']);
        $this->assertSame([], (new SchemaReconciler($db, $this->snapshot))->plan());
    }

    public function test_a_missing_foreign_key_on_an_existing_table_is_reported_not_added(): void
    {
        // Existing rows may break it (orphans, or a type the site changed), and a
        // failed constraint must not hang over every request.
        $db = $this->migrated();
        $db->statement('ALTER TABLE client_migrations DROP FOREIGN KEY fk_client_migrations_ticket');

        $result = (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $result['applied']);
        $this->assertCount(1, $result['reported']);
        $this->assertStringContainsString('fk_client_migrations_ticket', $result['reported'][0]);
        $this->assertArrayNotHasKey('fk_client_migrations_ticket', $db->model->tables['client_migrations']['foreign']);
    }

    public function test_a_not_null_date_without_a_default_is_added_nullable_to_a_table_with_rows(): void
    {
        // Strict mode rejects adding it to a table that has rows: there is no value
        // for those rows. Everything else keeps its definition.
        $this->assertSame('DATETIME NULL', SchemaReconciler::definitionForExistingRows('DATETIME NOT NULL'));
        $this->assertSame('TIMESTAMP NULL', SchemaReconciler::definitionForExistingRows('TIMESTAMP NOT NULL'));
        $this->assertSame('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', SchemaReconciler::definitionForExistingRows('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
        $this->assertSame('INT UNSIGNED NOT NULL', SchemaReconciler::definitionForExistingRows('INT UNSIGNED NOT NULL'));
        $this->assertSame("VARCHAR(20) NOT NULL DEFAULT 'x'", SchemaReconciler::definitionForExistingRows("VARCHAR(20) NOT NULL DEFAULT 'x'"));
    }

    public function test_it_runs_once_per_deploy_and_retries_a_failure_hourly(): void
    {
        $marker = $this->scratch . '/schema-reconcile.json';
        $reconciler = new SchemaReconciler($this->migrated(), $this->snapshot);

        $this->assertIsArray($reconciler->reconcileIfDue($marker), 'first boot after an upload checks');
        $this->assertNull($reconciler->reconcileIfDue($marker), 'later requests do not');
        $this->assertIsArray($reconciler->reconcileIfDue($marker, true), 'forced (e.g. the Migrator skipped a statement)');

        $written = json_decode((string) file_get_contents($marker), true);
        $this->assertTrue($written['ok']);
        $this->assertSame($reconciler->fingerprint(), $written['fingerprint']);

        // A new upload changes database/schema.php, so its fingerprint.
        file_put_contents($marker, json_encode(['fingerprint' => 'an-older-snapshot', 'at' => time(), 'ok' => true]));
        $this->assertIsArray($reconciler->reconcileIfDue($marker));

        // A failed run is retried, but not on every request.
        file_put_contents($marker, json_encode(['fingerprint' => $reconciler->fingerprint(), 'at' => time() - 60, 'ok' => false]));
        $this->assertNull($reconciler->reconcileIfDue($marker));
        file_put_contents($marker, json_encode(['fingerprint' => $reconciler->fingerprint(), 'at' => time() - 7200, 'ok' => false]));
        $this->assertIsArray($reconciler->reconcileIfDue($marker));
    }

    public function test_one_failing_repair_does_not_stop_the_others_and_is_retried(): void
    {
        $inner = $this->migrated();
        $inner->statement('ALTER TABLE domain_pricing DROP COLUMN grace_period_days, DROP COLUMN redemption_fee');
        $db = $this->throwingOn($inner, 'grace_period_days', 1118, 'Row size too large');
        $marker = $this->scratch . '/schema-reconcile.json';

        $result = (new SchemaReconciler($db, $this->snapshot))->reconcileIfDue($marker);

        $this->assertSame(['column domain_pricing.redemption_fee'], $result['applied']);
        $this->assertSame(['column domain_pricing.grace_period_days'], array_keys($result['failed']));
        $this->assertFalse(json_decode((string) file_get_contents($marker), true)['ok']);
    }

    public function test_a_change_another_request_made_first_counts_as_done(): void
    {
        // Two visitors right after an upload: both plan "add column", one loses.
        $inner = $this->migrated();
        $inner->statement('ALTER TABLE domain_pricing DROP COLUMN redemption_fee');
        $db = $this->throwingOn($inner, 'redemption_fee', 1060, "Duplicate column name 'redemption_fee'");

        $result = (new SchemaReconciler($db, $this->snapshot))->reconcile();

        $this->assertSame([], $result['failed']);
        $this->assertSame(['column domain_pricing.redemption_fee (already present)'], $result['applied']);
    }

    public function test_a_missing_snapshot_changes_nothing(): void
    {
        $reconciler = new SchemaReconciler($this->migrated(), $this->scratch . '/no-such-schema.php');

        $this->assertNull($reconciler->reconcileIfDue($this->scratch . '/marker.json'));
        $this->assertSame([], $reconciler->plan());
    }

    /** A Database that fails DDL mentioning $needle with a MySQL error code, as a server would. */
    private function throwingOn(SimulatedDatabase $inner, string $needle, int $code, string $message): Database
    {
        return new class ($inner, $needle, $code, $message) extends Database {
            public function __construct(
                private readonly SimulatedDatabase $inner,
                private readonly string $needle,
                private readonly int $code,
                private readonly string $message
            ) {
                parent::__construct('', '', '', '', '');
            }

            public function statement(string $sql, array $bindings = []): PDOStatement
            {
                if (str_starts_with(ltrim($sql), 'ALTER') && str_contains($sql, $this->needle)) {
                    $e = new PDOException("SQLSTATE[HY000]: General error: {$this->code} {$this->message}");
                    $e->errorInfo = ['HY000', $this->code, $this->message];
                    throw $e;
                }

                return $this->inner->statement($sql, $bindings);
            }

            public function select(string $sql, array $bindings = []): array
            {
                return $this->inner->select($sql, $bindings);
            }

            public function selectOne(string $sql, array $bindings = []): ?array
            {
                return $this->inner->selectOne($sql, $bindings);
            }
        };
    }
}
