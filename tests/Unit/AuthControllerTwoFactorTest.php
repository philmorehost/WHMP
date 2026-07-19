<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthController;
use CodeVault\Auth\AuthGuard;
use CodeVault\Auth\AuthManager;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Queue\SyncQueue;
use CodeVault\Request;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

final class AuthControllerTwoFactorTest extends DatabaseTestCase
{
    private AuthController $controller;
    private AdminRepository $admins;
    private AuthGuard $guard;
    private Totp $totp;
    private RecoveryCodes $recoveryCodes;
    private int $adminId;
    private string $secret;
    /** @var array<int, string> */
    private array $plainRecoveryCodes;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->totp = new Totp();
        $this->recoveryCodes = new RecoveryCodes();
        $this->secret = $this->totp->generateSecret();
        $this->plainRecoveryCodes = $this->recoveryCodes->generate();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, two_factor_secret, two_factor_enabled, two_factor_recovery_codes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'twofa-login', 'twofa-login@example.test',
                password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'TwoFA Login',
                $this->secret, 1, $this->recoveryCodes->hashForStorage($this->plainRecoveryCodes),
                $now, $now,
            ]
        );

        $this->admins = new AdminRepository($this->db);

        $configDir = sys_get_temp_dir() . '/codevault-2fa-login-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->guard = new AuthGuard($session, $this->admins, new RoleRepository($this->db));

        // Views call csrf_field()/csrf_token(), which resolve CsrfToken via
        // the App service locator — normally wired by Kernel, so a
        // hand-built controller test must set it up itself.
        $container = new Container();
        $container->instance(SessionManager::class, $session);
        App::setContainer($container);

        $hooks = new HookDispatcher();
        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            $hooks,
        );
        $auth = new AuthManager($this->admins, $bruteGuard, new AccountLockRepository($this->db), $hooks);

        $this->controller = new AuthController(
            $auth,
            $this->guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $session,
            $this->admins,
            $this->totp,
            $this->recoveryCodes,
            $hooks,
            new PasswordResetTokenRepository($this->db),
            new PasswordResetToken(),
            new EmailDispatcher(new EmailTemplateRepository($this->db), new EmailLogRepository($this->db), new SyncQueue()),
            new Config(sys_get_temp_dir() . '/codevault-2fa-login-test-noenv-' . uniqid())
        );
    }

    public function test_login_with_correct_password_redirects_to_the_2fa_challenge_without_authenticating(): void
    {
        $response = $this->controller->login($this->loginRequest('twofa-login', 'correct-horse-battery'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/login/2fa', $response->headers()['Location']);
        $this->assertFalse($this->guard->check(), 'password alone must not complete authentication when 2FA is enabled');
    }

    public function test_two_factor_form_redirects_to_login_when_nothing_is_pending(): void
    {
        $response = $this->controller->twoFactorForm($this->getRequest());

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
    }

    public function test_verify_two_factor_rejects_a_wrong_code_and_stays_unauthenticated(): void
    {
        $this->controller->login($this->loginRequest('twofa-login', 'correct-horse-battery'));

        $response = $this->controller->verifyTwoFactor($this->codeRequest('000000'));

        $this->assertSame(200, $response->status());
        $this->assertFalse($this->guard->check());
    }

    public function test_verify_two_factor_accepts_the_correct_totp_code_and_completes_login(): void
    {
        $this->controller->login($this->loginRequest('twofa-login', 'correct-horse-battery'));
        $code = $this->totp->currentCode($this->secret);

        $response = $this->controller->verifyTwoFactor($this->codeRequest($code));

        $this->assertSame(302, $response->status());
        $this->assertSame('/admin', $response->headers()['Location']);
        $this->assertTrue($this->guard->check());
    }

    public function test_verify_two_factor_accepts_a_recovery_code_and_consumes_it(): void
    {
        $this->controller->login($this->loginRequest('twofa-login', 'correct-horse-battery'));
        $recoveryCode = $this->plainRecoveryCodes[0];

        $response = $this->controller->verifyTwoFactor($this->codeRequest($recoveryCode));

        $this->assertSame(302, $response->status());
        $this->assertTrue($this->guard->check());

        // A second login must not accept the same recovery code again.
        $this->guard->logout();
        $this->controller->login($this->loginRequest('twofa-login', 'correct-horse-battery'));
        $reuse = $this->controller->verifyTwoFactor($this->codeRequest($recoveryCode));

        $this->assertSame(200, $reuse->status());
        $this->assertFalse($this->guard->check(), 'a consumed recovery code must not be usable a second time');
    }

    private function loginRequest(string $username, string $password): Request
    {
        return new Request([], ['username' => $username, 'password' => $password], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.50'], []);
    }

    private function codeRequest(string $code): Request
    {
        return new Request([], ['code' => $code], ['REQUEST_METHOD' => 'POST'], []);
    }

    private function getRequest(): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET'], []);
    }
}
