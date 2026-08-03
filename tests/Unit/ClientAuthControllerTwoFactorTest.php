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
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
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
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

final class ClientAuthControllerTwoFactorTest extends DatabaseTestCase
{
    private ClientAuthController $controller;
    private ClientRepository $clients;
    private ClientAuthGuard $guard;
    private Totp $totp;
    private RecoveryCodes $recoveryCodes;
    private int $clientId;
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

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'twofa-client-login@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'TwoFA',
            'last_name' => 'Client',
        ]);
        $this->clients->pendingTwoFactorSecret($this->clientId, $this->secret, $this->recoveryCodes->hashForStorage($this->plainRecoveryCodes));
        $this->clients->confirmTwoFactor($this->clientId);

        $configDir = sys_get_temp_dir() . '/codevault-client-2fa-login-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->guard = new ClientAuthGuard($session, $this->clients);

        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            new \CodeVault\Hooks\HookDispatcher(),
        );
        $auth = new ClientAuthManager($this->clients, $bruteGuard);

        $affiliateService = new AffiliateService(
            new AffiliateRepository($this->db),
            new AffiliateReferralRepository($this->db),
            new AffiliateCommissionRepository($this->db),
            new AffiliatePayoutRequestRepository($this->db),
            new InvoiceRepository($this->db)
        );

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        App::setContainer($container);

        $this->controller = new ClientAuthController(
            $auth,
            $this->guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $affiliateService,
            $session,
            $this->clients,
            $this->totp,
            $this->recoveryCodes,
            new PasswordResetTokenRepository($this->db),
            new PasswordResetToken(),
            new EmailDispatcher(new EmailTemplateRepository($this->db), new EmailLogRepository($this->db), new SyncQueue()),
            new Config(sys_get_temp_dir() . '/codevault-client-2fa-login-test-noenv-' . uniqid()),
            new SecurityQuestionModuleService(
                new ModuleManager(new HookDispatcher()),
                new SecurityQuestionModuleRepository($this->db),
                new ClientSecurityAnswerRepository($this->db)
            ),
            new SettingsRepository($this->db),
            new ClientRegistrationOtpRepository($this->db)
        );
    }

    public function test_login_with_correct_password_redirects_to_the_2fa_challenge_without_authenticating(): void
    {
        $response = $this->controller->login($this->loginRequest('twofa-client-login@example.test', 'correct-horse-battery'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/client/login/2fa', $response->headers()['Location']);
        $this->assertFalse($this->guard->check(), 'password alone must not complete authentication when 2FA is enabled');
    }

    public function test_two_factor_form_redirects_to_login_when_nothing_is_pending(): void
    {
        $response = $this->controller->twoFactorForm($this->getRequest());

        $this->assertSame(302, $response->status());
        $this->assertSame('/client/login', $response->headers()['Location']);
    }

    public function test_verify_two_factor_rejects_a_wrong_code_and_stays_unauthenticated(): void
    {
        $this->controller->login($this->loginRequest('twofa-client-login@example.test', 'correct-horse-battery'));

        $response = $this->controller->verifyTwoFactor($this->codeRequest('000000'));

        $this->assertSame(200, $response->status());
        $this->assertFalse($this->guard->check());
    }

    public function test_verify_two_factor_accepts_the_correct_totp_code_and_completes_login(): void
    {
        $this->controller->login($this->loginRequest('twofa-client-login@example.test', 'correct-horse-battery'));
        $code = $this->totp->currentCode($this->secret);

        $response = $this->controller->verifyTwoFactor($this->codeRequest($code));

        $this->assertSame(302, $response->status());
        $this->assertSame('/client/dashboard', $response->headers()['Location']);
        $this->assertTrue($this->guard->check());
    }

    public function test_verify_two_factor_accepts_a_recovery_code_and_consumes_it(): void
    {
        $this->controller->login($this->loginRequest('twofa-client-login@example.test', 'correct-horse-battery'));
        $recoveryCode = $this->plainRecoveryCodes[0];

        $response = $this->controller->verifyTwoFactor($this->codeRequest($recoveryCode));

        $this->assertSame(302, $response->status());
        $this->assertTrue($this->guard->check());

        $this->guard->logout();
        $this->controller->login($this->loginRequest('twofa-client-login@example.test', 'correct-horse-battery'));
        $reuse = $this->controller->verifyTwoFactor($this->codeRequest($recoveryCode));

        $this->assertSame(200, $reuse->status());
        $this->assertFalse($this->guard->check(), 'a consumed recovery code must not be usable a second time');
    }

    private function loginRequest(string $email, string $password): Request
    {
        return new Request([], ['email' => $email, 'password' => $password], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.60'], []);
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
