<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Affiliates\AffiliateCommissionRepository;
use CodeVault\Affiliates\AffiliatePayoutRequestRepository;
use CodeVault\Affiliates\AffiliateReferralRepository;
use CodeVault\Affiliates\AffiliateRepository;
use CodeVault\Affiliates\AffiliateService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientAuthController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientAuthManager;
use CodeVault\Clients\ClientRegistrationOtpRepository;
use CodeVault\Clients\ClientRepository;
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
use CodeVault\Modules\ClientSecurityAnswerRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\SecurityQuestionModuleRepository;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Queue\SyncQueue;
use CodeVault\Request;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Security\PhpassHasher;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

final class ClientAuthControllerPasswordResetTest extends DatabaseTestCase
{
    private ClientAuthController $controller;
    private ClientRepository $clients;
    private PasswordResetTokenRepository $resetTokens;
    private PasswordResetToken $resetToken;
    private SecurityQuestionModuleService $securityQuestions;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'resetclient@example.test',
            'password' => 'old-password-1',
            'first_name' => 'Reset',
            'last_name' => 'Client',
        ]);

        $this->resetTokens = new PasswordResetTokenRepository($this->db);
        $this->resetToken = new PasswordResetToken();

        $configDir = sys_get_temp_dir() . '/codevault-client-reset-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new ClientAuthGuard($session, $this->clients);

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        $container->instance(Mailer::class, new LogMailer(sys_get_temp_dir() . '/codevault-client-reset-mail-' . uniqid() . '.log'));
        App::setContainer($container);

        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            new HookDispatcher(),
        );
        $auth = new ClientAuthManager($this->clients, $bruteGuard, new SettingsRepository($this->db), new PhpassHasher());

        $affiliateService = new AffiliateService(
            new AffiliateRepository($this->db),
            new AffiliateReferralRepository($this->db),
            new AffiliateCommissionRepository($this->db),
            new AffiliatePayoutRequestRepository($this->db),
            new InvoiceRepository($this->db)
        );

        $mail = new EmailDispatcher(new EmailTemplateRepository($this->db), new EmailLogRepository($this->db), new SyncQueue());

        $this->securityQuestions = new SecurityQuestionModuleService(
            new ModuleManager(new HookDispatcher()),
            new SecurityQuestionModuleRepository($this->db),
            new ClientSecurityAnswerRepository($this->db)
        );

        $this->controller = new ClientAuthController(
            $auth,
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $affiliateService,
            $session,
            $this->clients,
            new Totp(),
            new RecoveryCodes(),
            $this->resetTokens,
            $this->resetToken,
            $mail,
            new Config(sys_get_temp_dir() . '/codevault-client-reset-noenv-' . uniqid()),
            $this->securityQuestions,
            new SettingsRepository($this->db),
            new ClientRegistrationOtpRepository($this->db)
        );
    }

    public function test_send_reset_link_issues_a_token_and_queues_an_email_for_a_real_account(): void
    {
        $response = $this->controller->sendResetLink($this->postRequest(['email' => 'resetclient@example.test']));

        $this->assertSame(200, $response->status());

        $tokenRow = $this->db->selectOne('SELECT * FROM password_reset_tokens WHERE account_type = ? AND account_id = ?', ['client', $this->clientId]);
        $this->assertNotNull($tokenRow);

        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'client_password_reset'");
        $this->assertCount(1, $emails);
    }

    public function test_send_reset_link_gives_the_identical_response_for_a_nonexistent_email_and_issues_no_token(): void
    {
        $response = $this->controller->sendResetLink($this->postRequest(['email' => 'nobody@example.test']));

        $this->assertSame(200, $response->status());
        $this->assertSame(0, count($this->db->select('SELECT * FROM password_reset_tokens')));
    }

    public function test_issuing_a_second_reset_link_invalidates_the_first(): void
    {
        $this->controller->sendResetLink($this->postRequest(['email' => 'resetclient@example.test']));
        $first = $this->db->selectOne('SELECT id FROM password_reset_tokens WHERE account_type = ? AND account_id = ?', ['client', $this->clientId]);

        $this->controller->sendResetLink($this->postRequest(['email' => 'resetclient@example.test']));
        $second = $this->db->selectOne('SELECT id FROM password_reset_tokens WHERE account_type = ? AND account_id = ?', ['client', $this->clientId]);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertCount(1, $this->db->select('SELECT * FROM password_reset_tokens'), 'only the most recently issued token should remain valid');
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
        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('old-password-1', $client['password_hash']), 'password must be unchanged');
    }

    public function test_reset_password_succeeds_and_the_token_becomes_single_use(): void
    {
        $token = $this->issueToken();

        $response = $this->controller->resetPassword($this->postRequest(['new_password' => 'brand-new-client-password']), ['token' => $token]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/client/login?reset=success', $response->headers()['Location']);

        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('brand-new-client-password', $client['password_hash']));

        $reuse = $this->controller->resetPassword($this->postRequest(['new_password' => 'another-password-1']), ['token' => $token]);
        $this->assertStringContainsString('Link Expired or Invalid', $reuse->body());
    }

    public function test_reset_password_rejects_a_wrong_security_question_answer_and_does_not_change_the_password(): void
    {
        $this->configureSecurityQuestion('Correct Answer');
        $token = $this->issueToken();

        $response = $this->controller->resetPassword($this->postRequest([
            'new_password' => 'brand-new-client-password',
            'security_answer' => 'Wrong Answer',
        ]), ['token' => $token]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('did not match', $response->body());

        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('old-password-1', $client['password_hash']), 'password must be unchanged');
    }

    public function test_reset_password_succeeds_with_the_correct_security_question_answer(): void
    {
        $this->configureSecurityQuestion('Correct Answer');
        $token = $this->issueToken();

        $response = $this->controller->resetPassword($this->postRequest([
            'new_password' => 'brand-new-client-password',
            'security_answer' => 'Correct Answer',
        ]), ['token' => $token]);

        $this->assertSame(302, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('brand-new-client-password', $client['password_hash']));
    }

    private function configureSecurityQuestion(string $answer): void
    {
        $answerRepository = new ClientSecurityAnswerRepository($this->db);
        $module = new \CodeVault\Security\MotherMaidenNameQuestion($answerRepository);

        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(\CodeVault\Modules\SecurityQuestionModule::class, 'test-question', $module);

        // Rebuild the service against a ModuleManager that actually knows
        // about the test module — the one built in setUp() is intentionally
        // bare (mirrors production wiring with zero registrations) so
        // existing tests exercise the "no question configured" path.
        $this->securityQuestions = new SecurityQuestionModuleService(
            $modules,
            new SecurityQuestionModuleRepository($this->db),
            new ClientSecurityAnswerRepository($this->db)
        );
        $this->securityQuestions->activate('test-question');
        $this->securityQuestions->setup($this->clientId, 'test-question', $answer);

        $this->controller = new ClientAuthController(
            new ClientAuthManager($this->clients, new BruteGuard(
                new LoginAttemptRepository($this->db),
                new IpRuleRepository($this->db),
                new CountryRuleRepository($this->db),
                new AccountLockRepository($this->db),
                new NullGeoIpResolver(),
                new HookDispatcher(),
            ), new SettingsRepository($this->db), new PhpassHasher()),
            new ClientAuthGuard(App::container()->make(SessionManager::class), $this->clients),
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new AffiliateService(
                new AffiliateRepository($this->db),
                new AffiliateReferralRepository($this->db),
                new AffiliateCommissionRepository($this->db),
                new AffiliatePayoutRequestRepository($this->db),
                new InvoiceRepository($this->db)
            ),
            App::container()->make(SessionManager::class),
            $this->clients,
            new Totp(),
            new RecoveryCodes(),
            $this->resetTokens,
            $this->resetToken,
            new EmailDispatcher(new EmailTemplateRepository($this->db), new EmailLogRepository($this->db), new SyncQueue()),
            new Config(sys_get_temp_dir() . '/codevault-client-reset-noenv-' . uniqid()),
            $this->securityQuestions,
            new SettingsRepository($this->db),
            new ClientRegistrationOtpRepository($this->db)
        );
    }

    private function issueToken(): string
    {
        $issued = $this->resetToken->generate();
        $this->resetTokens->issue('client', $this->clientId, $issued['hash']);

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
