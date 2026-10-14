<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Modules\AddonModuleService;
use CodeVault\Kernel;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Router;
use CodeVault\Session\SessionManager;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the CSRF gate at the same layer a real request hits it —
 * Kernel::handle() — rather than only unit-testing CsrfToken in
 * isolation. SessionManager is swapped for a no-op-start double (same
 * idea as AuthGuardPermissionTest) so the test drives $_SESSION directly
 * instead of going through a real PHP session lifecycle; everything else
 * (the container, the CSRF check itself, the router) is the real thing.
 *
 * It runs with no database at all: Database is pinned to a ScriptedDatabase
 * (which answers "no rows" instead of connecting), and the boot plumbing that
 * an installed site runs on every request — migrations, schema reconcile,
 * addon boot — is pinned to no-op doubles, because none of it is what this
 * test is about. A fresh clone with no MariaDB must be able to prove the CSRF
 * gate.
 */
final class CsrfProtectionTest extends TestCase
{
    private string $basePath;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        $_SESSION = [];

        // A throwaway "installed site". Kernel::handle() reads exactly two
        // things from disk before it reaches the CSRF gate: the install lock
        // (an uninstalled site is redirected to /install, never reaching the
        // gate) and configuration. A temporary base path gives the test both
        // without touching the checkout, and the migration/schema-boot steps
        // find nothing to run.
        $this->basePath = sys_get_temp_dir() . '/codevault-csrf-site-' . uniqid();
        mkdir($this->basePath);
        mkdir($this->basePath . '/config');
        file_put_contents($this->basePath . '/.installed.lock', date('c'));

        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-csrf-e2e-' . uniqid();
        mkdir($this->emptyConfigDir);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        @unlink($this->basePath . '/.installed.lock');
        @rmdir($this->basePath . '/config');
        @rmdir($this->basePath);
    }

    private function kernelWithTestRoute(string $method, string $path): Kernel
    {
        $kernel = new Kernel($this->basePath);

        // The CSRF gate must be provable without MariaDB: pin a database that
        // answers "no rows" instead of connecting, so every DB-backed step in
        // Kernel::handle() is a no-op here — storefront resolution included.
        // The check under test, the token gate, lives above all of them.
        $kernel->container->instance(Database::class, new ScriptedDatabase());

        // An installed site also runs pending migrations before every request.
        // There is no database here, and no migrations in the throwaway base
        // path either, so pin a no-op: what this test proves is the gate below,
        // and a boot step that can only fail would bury it in error_log noise.
        $kernel->container->instance(Migrator::class, new class extends Migrator {
            public function __construct()
            {
            }

            public function run(bool $continueOnError = false): array
            {
                return [];
            }

            public function tolerated(): array
            {
                return [];
            }
        });

        $session = new class (new Config($this->emptyConfigDir)) extends SessionManager {
            public function start(): void
            {
            }
        };

        $kernel->container->instance(SessionManager::class, $session);

        /** @var Router $router */
        $router = $kernel->container->make(Router::class);
        $router->{strtolower($method)}($path, fn () => Response::html('ok'));

        return $kernel;
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): Request
    {
        return new Request([], $body, [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
        ], []);
    }

    public function test_post_without_a_token_is_rejected_with_403(): void
    {
        $kernel = $this->kernelWithTestRoute('POST', '/__test/csrf-target');

        $response = $kernel->handle($this->request('POST', '/__test/csrf-target'));

        $this->assertSame(403, $response->status());
    }

    public function test_post_with_the_wrong_token_is_rejected_with_403(): void
    {
        $kernel = $this->kernelWithTestRoute('POST', '/__test/csrf-target');
        $_SESSION['_csrf_token'] = 'the-real-token';

        $response = $kernel->handle($this->request('POST', '/__test/csrf-target', ['_token' => 'a-forged-token']));

        $this->assertSame(403, $response->status());
    }

    public function test_post_with_the_correct_token_succeeds(): void
    {
        $kernel = $this->kernelWithTestRoute('POST', '/__test/csrf-target');
        $_SESSION['_csrf_token'] = 'the-real-token';

        $response = $kernel->handle($this->request('POST', '/__test/csrf-target', ['_token' => 'the-real-token']));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('ok', $response->body());
    }

    public function test_get_requests_are_not_subject_to_the_csrf_check(): void
    {
        $kernel = $this->kernelWithTestRoute('GET', '/__test/csrf-get-target');

        $response = $kernel->handle($this->request('GET', '/__test/csrf-get-target'));

        $this->assertSame(200, $response->status());
    }

    public function test_api_routes_are_exempt_from_the_csrf_check(): void
    {
        $kernel = $this->kernelWithTestRoute('POST', '/api/__test/webhook');

        $response = $kernel->handle($this->request('POST', '/api/__test/webhook'));

        $this->assertSame(200, $response->status());
    }

    public function test_install_routes_are_exempt_from_the_csrf_check(): void
    {
        $kernel = $this->kernelWithTestRoute('POST', '/install/__test');

        $response = $kernel->handle($this->request('POST', '/install/__test'));

        $this->assertSame(200, $response->status());
    }
}
