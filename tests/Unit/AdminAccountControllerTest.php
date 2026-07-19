<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminAccountController;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Request;
use CodeVault\Security\CsrfToken;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

final class AdminAccountControllerTest extends DatabaseTestCase
{
    private AdminAccountController $controller;
    private AdminRepository $admins;
    private AuthGuard $guard;
    private Totp $totp;
    private RecoveryCodes $recoveryCodes;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['acctholder', 'acctholder@example.test', password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'Acct Holder', $now, $now]
        );

        $this->admins = new AdminRepository($this->db);

        $configDir = sys_get_temp_dir() . '/codevault-2fa-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->guard = new AuthGuard($session, $this->admins, new RoleRepository($this->db));
        $this->guard->login($this->admins->findById($this->adminId));

        // Views call the csrf_field()/csrf_token() helpers, which resolve
        // CsrfToken via the App service locator — normally wired by Kernel,
        // so a hand-built controller test must set it up itself.
        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        $this->totp = new Totp();
        $this->recoveryCodes = new RecoveryCodes();

        $this->controller = new AdminAccountController(
            $this->guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->admins,
            $this->totp,
            $this->recoveryCodes,
            new Config(sys_get_temp_dir() . '/codevault-2fa-test-noenv-' . uniqid())
        );
    }

    public function test_enable_stores_a_pending_secret_without_enabling_two_factor(): void
    {
        $response = $this->controller->enable($this->request());

        $this->assertSame(200, $response->status());

        $admin = $this->admins->findById($this->adminId);
        $this->assertNotNull($admin['two_factor_secret']);
        $this->assertSame(0, (int) $admin['two_factor_enabled']);
        $this->assertNotNull($admin['two_factor_recovery_codes']);
    }

    public function test_confirm_with_wrong_code_does_not_enable_two_factor(): void
    {
        $this->controller->enable($this->request());

        $response = $this->controller->confirm($this->request(['code' => '000000']));

        $this->assertSame(200, $response->status());
        $admin = $this->admins->findById($this->adminId);
        $this->assertSame(0, (int) $admin['two_factor_enabled']);
    }

    public function test_confirm_with_correct_code_enables_two_factor(): void
    {
        $this->controller->enable($this->request());
        $secret = (string) $this->admins->findById($this->adminId)['two_factor_secret'];
        $code = $this->totp->currentCode($secret);

        $response = $this->controller->confirm($this->request(['code' => $code]));

        $this->assertSame(302, $response->status());
        $admin = $this->admins->findById($this->adminId);
        $this->assertSame(1, (int) $admin['two_factor_enabled']);
    }

    public function test_disable_with_wrong_password_leaves_two_factor_enabled(): void
    {
        $this->controller->enable($this->request());
        $secret = (string) $this->admins->findById($this->adminId)['two_factor_secret'];
        $this->controller->confirm($this->request(['code' => $this->totp->currentCode($secret)]));

        $response = $this->controller->disable($this->request(['password' => 'wrong-password']));

        $this->assertSame(200, $response->status());
        $admin = $this->admins->findById($this->adminId);
        $this->assertSame(1, (int) $admin['two_factor_enabled']);
    }

    public function test_disable_with_correct_password_clears_two_factor_state(): void
    {
        $this->controller->enable($this->request());
        $secret = (string) $this->admins->findById($this->adminId)['two_factor_secret'];
        $this->controller->confirm($this->request(['code' => $this->totp->currentCode($secret)]));

        $response = $this->controller->disable($this->request(['password' => 'correct-horse-battery']));

        $this->assertSame(302, $response->status());
        $admin = $this->admins->findById($this->adminId);
        $this->assertSame(0, (int) $admin['two_factor_enabled']);
        $this->assertNull($admin['two_factor_secret']);
        $this->assertNull($admin['two_factor_recovery_codes']);
    }

    /** @param array<string, mixed> $body */
    private function request(array $body = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }
}
