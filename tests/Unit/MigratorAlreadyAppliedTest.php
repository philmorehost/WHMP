<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database;
use CodeVault\Database\Migrator;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * A plain-SQL migration whose change is already in place (SchemaReconciler added the
 * column first, or someone patched the site by hand) must not fail on every boot
 * forever. Anything else must still fail.
 */
final class MigratorAlreadyAppliedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrator-tolerance-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function migration(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, "<?php\nreturn ['up' => [{$body}]];\n");
    }

    /**
     * A Database whose server answers each statement containing a key of $errors
     * with that MySQL error, and records what was executed and which migrations
     * were recorded as applied.
     *
     * @param array<string, int> $errors
     */
    private function database(array $errors): Database
    {
        return new class ($errors) extends Database {
            /** @var array<int, string> */
            public array $executed = [];
            /** @var array<int, string> */
            public array $recorded = [];
            private PDO $pdo;

            public function __construct(array $errors)
            {
                parent::__construct('', '', '', '', '');
                $owner = $this;

                $this->pdo = new class ($errors, $owner) extends PDO {
                    public function __construct(private readonly array $errors, private readonly object $owner)
                    {
                    }

                    public function exec(string $statement): int|false
                    {
                        return 0; // CREATE TABLE IF NOT EXISTS migrations
                    }

                    public function prepare(string $query, array $options = []): PDOStatement|false
                    {
                        return new class ($query, $this->errors, $this->owner) extends PDOStatement {
                            public function __construct(private readonly string $sql, private readonly array $errors, private readonly object $owner)
                            {
                            }

                            public function execute(?array $params = null): bool
                            {
                                foreach ($this->errors as $needle => $code) {
                                    if (str_contains($this->sql, $needle)) {
                                        $e = new PDOException("SQLSTATE[42000]: {$code} simulated server error");
                                        $e->errorInfo = ['42000', $code, 'simulated server error'];
                                        throw $e;
                                    }
                                }
                                $this->owner->executed[] = $this->sql;

                                return true;
                            }
                        };
                    }
                };
            }

            public function connection(): PDO
            {
                return $this->pdo;
            }

            public function select(string $sql, array $bindings = []): array
            {
                return array_map(static fn (string $m): array => ['migration' => $m], $this->recorded);
            }

            public function insert(string $sql, array $bindings = []): string
            {
                $this->recorded[] = (string) $bindings[0];

                return (string) count($this->recorded);
            }
        };
    }

    public function test_a_column_that_already_exists_does_not_keep_the_migration_pending(): void
    {
        $this->migration('0001_add_fee.php', "'ALTER TABLE domain_pricing ADD COLUMN redemption_fee DECIMAL(10,2)', 'UPDATE domain_pricing SET redemption_fee = 0'");
        $db = $this->database(['ADD COLUMN redemption_fee' => 1060]);
        $migrator = new Migrator($db, $this->dir);

        $ran = $migrator->run(true);

        $this->assertSame(['0001_add_fee.php'], $ran);
        $this->assertSame([], $migrator->failures());
        $this->assertSame(['0001_add_fee.php'], $db->recorded);
        $this->assertSame(['UPDATE domain_pricing SET redemption_fee = 0'], $db->executed, 'later statements in the file still run');
        $this->assertArrayHasKey('0001_add_fee.php', $migrator->tolerated(), 'reported, so the boot path re-checks the schema');
    }

    public function test_each_already_done_error_is_tolerated(): void
    {
        foreach (Migrator::ALREADY_DONE_ERRORS as $i => $code) {
            $this->migration(sprintf('%04d_m.php', $i + 1), "'STATEMENT {$code}'");
        }
        $db = $this->database(array_combine(
            array_map(static fn (int $c): string => "STATEMENT {$c}", Migrator::ALREADY_DONE_ERRORS),
            Migrator::ALREADY_DONE_ERRORS
        ));
        $migrator = new Migrator($db, $this->dir);

        $migrator->run(true);

        $this->assertSame([], $migrator->failures());
        $this->assertCount(count(Migrator::ALREADY_DONE_ERRORS), $db->recorded);
    }

    public function test_a_real_error_still_fails_and_is_retried_next_boot(): void
    {
        // 1146 table doesn't exist, 1064 syntax, 1062 duplicate row (a partial seed
        // INSERT inserted none of its rows, so it is not "already done").
        foreach ([1146, 1064, 1062] as $code) {
            $this->migration("m_{$code}.php", "'STATEMENT {$code}'");
        }
        $db = $this->database(['STATEMENT 1146' => 1146, 'STATEMENT 1064' => 1064, 'STATEMENT 1062' => 1062]);
        $migrator = new Migrator($db, $this->dir);

        $migrator->run(true);

        $this->assertSame(['m_1062.php', 'm_1064.php', 'm_1146.php'], array_keys($migrator->failures()));
        $this->assertSame([], $db->recorded);
        $this->assertSame([], $migrator->tolerated());
    }

    public function test_a_closure_migration_still_fails_on_an_already_exists_error(): void
    {
        // A closure may have done part of its work before the error, so "the last
        // statement's change exists" says nothing about the rest of it.
        $this->migration('0001_closure.php', "static function (\\CodeVault\\Database \$db): void { \$e = new \\PDOException('dup'); \$e->errorInfo = ['42S21', 1060, 'dup']; throw \$e; }");
        $db = $this->database([]);
        $migrator = new Migrator($db, $this->dir);

        $migrator->run(true);

        $this->assertArrayHasKey('0001_closure.php', $migrator->failures());
        $this->assertSame([], $db->recorded);
    }
}
