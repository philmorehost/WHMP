<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthController;
use CodeVault\Auth\AuthGuard;
use CodeVault\Auth\AuthManager;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Queue\SyncQueue;
use CodeVault\Request;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
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

final class AuthControllerPasswordResetTest extends DatabaseTestCase
{
    private AuthController $controller;
    private AdminRepository $admins;
    private PasswordResetTokenRepository $resetTokens;
    private PasswordResetToken $resetToken;
    private EmailLogRepository $emailLog;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['resetadmin', 'resetadmin@example.test', password_hash('old-password-1', PASSWORD_ARGON2ID), 'Reset Admin', $now, $now]
        );

        $this->admins = new AdminRepository($this->db);
        $this->resetTokens = new PasswordResetTokenRepository($this->db);
        $this->resetToken = new PasswordResetToken();
        $this->emailLog = new EmailLogRepository($this->db);

        $configDir = sys_get_temp_dir() . '/codevault-admin-reset-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new AuthGuard($session, $this->admins, new RoleRepository($this->db));

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        $container->instance(Mailer::class, new LogMailer(sys_get_temp_dir() . '/codevault-admin-reset-mail-' . uniqid() . '.log'));
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

        $mail = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());

        $this->controller = new AuthController(
            $auth,
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $session,
            $this->admins,
            new Totp(),
            new RecoveryCodes(),
            $hooks,
            $this->resetTokens,
            $this->resetToken,
            $mail,
            new Config(sys_get_temp_dir() . '/codevault-admin-reset-noenv-' . uniqid())
        );
    }

    public function test_send_reset_link_issues_a_token_and_queues_an_email_for_a_real_account(): void
    {
        $response = $this->controller->sendResetLink($this->postRequest(['email' => 'resetadmin@example.test']));

        $this->assertSame(200, $response->status());

        $tokenRow = $this->db->selectOne('SELECT * FROM password_reset_tokens WHERE account_type = ? AND account_id = ?', ['admin', $this->adminId]);
        $this->assertNotNull($tokenRow);

        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'admin_password_reset'");
        $this->assertCount(1, $emails);
    }

    public function test_send_reset_link_gives_the_identical_response_for_a_nonexistent_email_and_issues_no_token(): void
    {
        $response = $this->controller->sendResetLink($this->postRequest(['email' => 'nobody@example.test']));

        $this->assertSame(200, $response->status());
        $this->assertSame(0, count($this->db->select('SELECT * FROM password_reset_tokens')));
    }

    public function test_reset_password_form_rejects_a_bogus_token(): void
    {
        $response = $this->controller->resetPasswordForm($this->getRequest(), ['token' => 'not-a-real-token']);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Link Expired or Invalid', $response->body());
    }

    public function test_reset_password_rejects_a_password_shorter_than_8_characters(): void
    {
        $token = $this->issueToken();

        $response = $this->controller->resetPassword($this->postRequest(['new_password' => 'short']), ['token' => $token]);

        $this->assertSame(200, $response->status());
        $admin = $this->admins->findById($this->adminId);
        $this->assertTrue(password_verify('old-password-1', $admin['password_hash']), 'password must be unchanged');
    }

    public function test_reset_password_succeeds_and_the_token_becomes_single_use(): void
    {
        $token = $this->issueToken();

        $response = $this->controller->resetPassword($this->postRequest(['new_password' => 'brand-new-admin-password']), ['token' => $token]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/login?reset=success', $response->headers()['Location']);

        $admin = $this->admins->findById($this->adminId);
        $this->assertTrue(password_verify('brand-new-admin-password', $admin['password_hash']));

        $reuse = $this->controller->resetPassword($this->postRequest(['new_password' => 'another-password-1']), ['token' => $token]);
        $this->assertStringContainsString('Link Expired or Invalid', $reuse->body());
    }

    private function issueToken(): string
    {
        $issued = $this->resetToken->generate();
        $this->resetTokens->issue('admin', $this->adminId, $issued['hash']);

        return $issued['token'];
    }

    /** @param array<string, mixed> $body */
    private function postRequest(array $body): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }

    private function getRequest(): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET'], []);
    }
}
