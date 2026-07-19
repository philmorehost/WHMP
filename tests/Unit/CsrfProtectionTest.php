<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Kernel;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Router;
use CodeVault\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the CSRF gate at the same layer a real request hits it —
 * Kernel::handle() — rather than only unit-testing CsrfToken in
 * isolation. SessionManager is swapped for a no-op-start double (same
 * idea as AuthGuardPermissionTest) so the test drives $_SESSION directly
 * instead of going through a real PHP session lifecycle; everything else
 * (the container, the CSRF check itself, the router) is the real thing.
 */
final class CsrfProtectionTest extends TestCase
{
    private string $basePath;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->basePath = dirname(__DIR__, 2);
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-csrf-e2e-' . uniqid();
        mkdir($this->emptyConfigDir);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
    }

    private function kernelWithTestRoute(string $method, string $path): Kernel
    {
        $kernel = new Kernel($this->basePath);

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
