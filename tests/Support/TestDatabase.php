<?php

declare(strict_types=1);

namespace CodeVault\Tests\Support;

use CodeVault\Database;

/**
 * Where the integration tests get their database from.
 *
 * Everything the suite needs to talk to MariaDB is resolved here, from the same
 * environment variables the application itself uses, so CI, Docker Compose and a
 * developer's laptop all describe the connection the same way:
 *
 *   DB_HOST           (default 127.0.0.1)
 *   DB_PORT           (default 3306)
 *   DB_USERNAME       (default root)
 *   DB_PASSWORD       (default empty)
 *   DB_TEST_DATABASE  (default codevault_test)  — the throwaway schema tests DROP
 *
 * Two safety rules, because the suite drops every table it finds:
 *
 *   1. The test schema is its OWN variable (`DB_TEST_DATABASE`). It is never
 *      taken from `DB_DATABASE`, so pointing `.env` at a production database
 *      cannot make the suite wipe it.
 *   2. If `DB_TEST_DATABASE` happens to equal `DB_DATABASE`, or looks like a
 *      production name, the suite refuses to run those tests rather than
 *      destroying data (see {@see self::skipReason()}).
 *
 * Set `CODEVAULT_SKIP_DB_TESTS=1` to skip every database-backed test explicitly
 * (useful on a machine with no MariaDB, or when you only want the pure-unit
 * subset). Tests that need a database also skip themselves when the server
 * simply isn't reachable.
 */
final class TestDatabase
{
    /** Names we refuse to run destructive tests against, whatever the env says. */
    private const FORBIDDEN_NAMES = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    private static bool $envLoaded = false;

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public static function config(): array
    {
        return [
            'host' => self::env('DB_HOST', '127.0.0.1'),
            'port' => self::env('DB_PORT', '3306'),
            'database' => self::env('DB_TEST_DATABASE', 'codevault_test'),
            'username' => self::env('DB_USERNAME', 'root'),
            'password' => self::env('DB_PASSWORD', ''),
        ];
    }

    public static function host(): string
    {
        return self::config()['host'];
    }

    public static function port(): string
    {
        return self::config()['port'];
    }

    public static function database(): string
    {
        return self::config()['database'];
    }

    public static function username(): string
    {
        return self::config()['username'];
    }

    public static function password(): string
    {
        return self::config()['password'];
    }

    /** A connection to the test schema. */
    public static function connection(): Database
    {
        $c = self::config();

        return new Database($c['host'], $c['port'], $c['database'], $c['username'], $c['password']);
    }

    /** A connection with no schema selected — used to CREATE/DROP the test schema. */
    public static function serverDsn(): string
    {
        $c = self::config();

        return "mysql:host={$c['host']};port={$c['port']}";
    }

    public static function dsn(?string $database = null): string
    {
        $c = self::config();
        $database ??= $c['database'];

        return "mysql:host={$c['host']};port={$c['port']};dbname={$database}";
    }

    /**
     * Reason the database-backed tests should be skipped, or null when they
     * should run. Checked before any PDO object is created, so nothing tries to
     * connect to a server we already know we do not want to touch.
     */
    public static function skipReason(): ?string
    {
        if (self::truthy(self::env('CODEVAULT_SKIP_DB_TESTS', ''))) {
            return 'CODEVAULT_SKIP_DB_TESTS is set — database-backed tests skipped on purpose.';
        }

        $test = self::database();

        if (in_array(strtolower($test), self::FORBIDDEN_NAMES, true)) {
            return "Refusing to run destructive tests against system schema '{$test}'.";
        }

        if ($test === '') {
            return 'No test database configured (set DB_TEST_DATABASE).';
        }

        $app = self::env('DB_DATABASE', '');

        if ($app !== '' && $app === $test) {
            return "DB_TEST_DATABASE equals DB_DATABASE ('{$test}'); refusing to drop the application's own tables. "
                . 'Point DB_TEST_DATABASE at a throwaway schema such as codevault_test.';
        }

        return null;
    }

    /**
     * Read an env var the same way the application does: `.env` first (only if
     * the process environment has not already defined the key), then real
     * environment variables — so CI's `env:` and Compose's `environment:`
     * always win over a developer's local `.env` file.
     */
    public static function env(string $key, string $default = ''): string
    {
        self::loadEnvFile();

        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private static function loadEnvFile(): void
    {
        if (self::$envLoaded) {
            return;
        }

        self::$envLoaded = true;

        $path = dirname(__DIR__, 2) . '/.env';

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");

            if (getenv($key) === false && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * A PDO handle on the server itself, with no schema selected — for
     * CREATE/DROP DATABASE style setup that the test suite does for throwaway
     * schemas.
     */
    public static function serverConnection(): \PDO
    {
        return new \PDO(self::serverDsn(), self::username(), self::password(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * A second, throwaway schema (an import source, for example) named after the
     * test schema so the two always live side by side: `codevault_test` and
     * `codevault_test_whmcs_remote`.
     */
    public static function schemaName(string $suffix): string
    {
        return self::database() . '_' . $suffix;
    }

    public static function dropSchema(string $name): void
    {
        if (!self::isSafeSchemaName($name)) {
            return;
        }

        self::serverConnection()->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }

    public static function createSchema(string $name): void
    {
        self::serverConnection()->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '`');
    }

    /** A schema name we are willing to drop: prefixed with the test schema, never a system one. */
    public static function isSafeSchemaName(string $name): bool
    {
        if ($name === '' || in_array(strtolower($name), self::FORBIDDEN_NAMES, true)) {
            return false;
        }

        return str_starts_with($name, self::database() . '_');
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
