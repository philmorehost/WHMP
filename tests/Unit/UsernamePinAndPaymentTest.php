<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentCallbackController;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Security\CsrfToken;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\UsernameChanger\UsernamePolicy;
use CodeVault\View;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\UsernameChanger\UsernameChangeNotifier;
use CodeVault\UsernameChanger\UsernameChangePayment;
use CodeVault\UsernameChanger\UsernameChangePinReset;
use CodeVault\UsernameChanger\UsernameChangeRepository;
use CodeVault\UsernameChanger\UsernameChangerSettings;
use CodeVault\UsernameChanger\UsernameChangeTemplates;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The username modal's in-place Security PIN reset and its Pay step.
 */
final class UsernamePinAndPaymentTest extends TestCase
{
    private ScriptedDatabase $db;
    private CapturingQueue $queue;
    private SessionManager $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = new ScriptedDatabase();
        $this->queue = new CapturingQueue();
        $config = new Config(sys_get_temp_dir() . '/cv-ucn-' . uniqid());
        $this->session = new SessionManager($config);
        $templates = UsernameChangeTemplates::all();
        $this->db->on('/FROM email_templates WHERE `key`/', static fn (array $b): array => isset($templates[$b[0]])
            ? [['key' => $b[0], 'subject' => $templates[$b[0]]['subject'], 'body_html' => $templates[$b[0]]['body']]]
            : []);
    }

    // ------------------------------------------------------------ PIN reset

    private function pinReset(): UsernameChangePinReset
    {
        $settingsRepo = new SettingsRepository($this->db);
        $notifier = new UsernameChangeNotifier(
            new EmailDispatcher(new EmailTemplateRepository($this->db), new EmailLogRepository($this->db), $this->queue),
            $settingsRepo,
            new Config(sys_get_temp_dir() . '/cv-ucn-' . uniqid()),
            new UsernameChangerSettings($settingsRepo)
        );

        return new UsernameChangePinReset($this->db, new UsernameChangeRepository($this->db), $notifier, $this->session);
    }

    /** @return array<string, mixed> */
    private static function client(array $over = []): array
    {
        return $over + ['id' => 5, 'email' => 'ada@example.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'reseller_id' => null,
            'password_hash' => password_hash('correct horse', PASSWORD_DEFAULT)];
    }

    private function write(string $pattern): ?array
    {
        foreach ($this->db->writes as $w) {
            if (preg_match($pattern, $w['sql']) === 1) {
                return $w;
            }
        }

        return null;
    }

    public function test_reset_by_password_saves_the_pin_and_lifts_the_pin_lockout(): void
    {
        $r = $this->pinReset()->reset(self::client(), 'password', 'correct horse', '4821', '4821');

        $this->assertTrue($r['ok'], $r['message']);
        $update = $this->write('/UPDATE clients SET security_pin_hash = \?/');
        $this->assertNotNull($update);
        $this->assertTrue(password_verify('4821', $update['bindings'][0]), 'stored as a verifiable hash');
        $this->assertSame(5, $update['bindings'][2]);
        $clear = $this->write('/DELETE FROM username_change_throttle WHERE throttle_key = \?/');
        $this->assertNotNull($clear, 'the old PIN lockout is lifted');
        $this->assertSame(['pin:5'], $clear['bindings']);
        $this->assertSame('Your Security PIN was changed', $this->queue->only()->subject, 'a security notice is sent');
    }

    public function test_wrong_password_mismatch_and_bad_length_change_nothing(): void
    {
        $p = $this->pinReset();

        $this->assertSame('password', $p->reset(self::client(), 'password', 'nope', '4821', '4821')['code']);
        $this->assertSame('pin_mismatch', $p->reset(self::client(), 'password', 'correct horse', '4821', '4822')['code']);
        $this->assertSame('pin_length', $p->reset(self::client(), 'password', 'correct horse', '12', '12')['code']);
        $this->assertSame('pin_length', $p->reset(self::client(), 'password', 'correct horse', str_repeat('9', 13), str_repeat('9', 13))['code']);
        $this->assertNull($this->write('/UPDATE clients/'));
        $this->assertSame([], $this->queue->jobs);
    }

    public function test_a_client_without_a_password_can_only_use_an_emailed_code(): void
    {
        $google = self::client(['password_hash' => '']);
        $p = $this->pinReset();

        $this->assertSame(['code'], $p->methods($google));
        $this->assertSame(['code', 'password'], $p->methods(self::client()));
        $this->assertSame('password', $p->reset($google, 'password', '', '4821', '4821')['code']);
    }

    public function test_emailed_code_resets_the_pin_and_is_never_mirrored_into_notifications(): void
    {
        $p = $this->pinReset();
        $sent = $p->sendCode(self::client());

        $this->assertTrue($sent['ok'], $sent['message']);
        $this->assertStringContainsString('a••@example.com', $sent['message'], 'the address is masked');
        $job = $this->queue->only();
        $this->assertSame('ada@example.com', $job->to);
        $this->assertSame(1, preg_match('/code: (\d{6})$/', $job->subject, $m));
        $code = $m[1];
        $log = $this->write('/INSERT INTO email_log/');
        $this->assertNull($log['bindings'][3], 'sent without a client id, so it is not copied into in-app notifications');
        $this->assertStringNotContainsString($code, json_encode($_SESSION), 'only a hash of the code is kept');

        $this->assertSame('code', $p->reset(self::client(), 'code', '000000' === $code ? '111111' : '000000', '4821', '4821')['code']);
        $ok = $p->reset(self::client(), 'code', $code, '4821', '4821');
        $this->assertTrue($ok['ok'], $ok['message']);
        $this->assertSame('code_missing', $p->reset(self::client(), 'code', $code, '1234', '1234')['code'], 'a code works once');
    }

    public function test_codes_expire_and_lock_after_five_wrong_tries(): void
    {
        $p = $this->pinReset();
        $p->sendCode(self::client());
        $_SESSION['ucn_pin_code']['exp'] = time() - 1;
        $this->assertSame('code_expired', $p->reset(self::client(), 'code', '123456', '4821', '4821')['code']);

        $p->sendCode(self::client());
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('code', $p->reset(self::client(), 'code', 'x', '4821', '4821')['code']);
        }
        $this->assertSame('code_expired', $p->reset(self::client(), 'code', 'x', '4821', '4821')['code'], 'the fifth wrong try burns the code');
        $this->assertArrayNotHasKey('ucn_pin_code', $_SESSION);
    }

    public function test_a_code_issued_to_another_client_does_not_work(): void
    {
        $p = $this->pinReset();
        $p->sendCode(self::client(['id' => 6]));

        $this->assertSame('code_missing', $p->reset(self::client(), 'code', '123456', '4821', '4821')['code']);
    }

    // ------------------------------------------------------------ payment

    /** @var array<int, int> */
    private array $paidHooks = [];

    private function payment(float $wallet, array $invoice = [], array $gateways = [], int $lock = 1): UsernameChangePayment
    {
        $db = $this->db;
        $inv = $invoice + ['id' => 77, 'client_id' => 5, 'status' => 'unpaid', 'total' => 5.0, 'subtotal' => 5.0,
            'currency_id' => null, 'currency_rate' => 1.0, 'parent_invoice_id' => null];
        $paidNow = static function () use ($db): bool {
            foreach ($db->writes as $w) {
                if (str_starts_with($w['sql'], 'UPDATE invoices SET status')) {
                    return true;
                }
            }

            return false;
        };
        $db->on('/FROM invoices WHERE id = \?/', static fn (): array => [['status' => $paidNow() ? 'paid' : $inv['status']] + $inv]);
        $db->on('/FROM transactions WHERE invoice_id = \?/', static function () use ($db, $inv): array {
            foreach ($db->writes as $w) {
                if (str_starts_with($w['sql'], 'INSERT INTO transactions')) {
                    return [['total' => $inv['total']]];
                }
            }

            return [['total' => 0]];
        });
        $db->on('/FROM client_credit_ledger WHERE client_id/', [['balance' => $wallet]]);
        $db->on('/FROM payment_gateways/', $gateways);
        $db->on('/FROM currencies/', [['id' => 1, 'code' => 'NGN', 'symbol' => '₦', 'exchange_rate' => 1, 'is_default' => 1, 'is_pricing' => 1]]);
        $db->on('/GET_LOCK/', [['l' => $lock]]);

        $hooks = new HookDispatcher();
        $hooks->register(HookPoints::INVOICE_PAID, function (array $payload): void {
            $this->paidHooks[] = (int) $payload['invoiceId'];
        });
        $invoices = new InvoiceRepository($db);
        $transactions = new TransactionRepository($db);
        $credit = new ClientCreditRepository($db);

        return new UsernameChangePayment(
            $db,
            $credit,
            new CreditService($credit, $invoices, $transactions, new PaymentService($invoices, $transactions, $hooks, $db, $credit), $hooks),
            $transactions,
            new PaymentGatewayRepository($db),
            new CurrencyService(new CurrencyRepository($db))
        );
    }

    /** @return array<string, mixed> */
    private static function request(array $over = []): array
    {
        return $over + ['id' => 31, 'service_id' => 10, 'client_id' => 5, 'status' => 'awaiting_payment', 'invoice_id' => 77];
    }

    public function test_the_pay_step_offers_wallet_and_only_usable_gateways(): void
    {
        $s = $this->payment(20.0, [], [
            ['slug' => 'manual', 'name' => 'Bank transfer', 'config' => json_encode(['bank_details' => 'GTB 0123'])],
            ['slug' => 'paystack', 'name' => 'Paystack', 'config' => json_encode(['secret_key' => 'sk'])],
            ['slug' => 'flutterwave', 'name' => 'Flutterwave', 'config' => '{}'],
            ['slug' => 'payhub', 'name' => 'PayHub', 'config' => json_encode(['secret_key' => 'sk', 'public_key' => 'pk'])],
        ])->summary(self::request(), self::client());

        $this->assertNotNull($s);
        $this->assertSame('₦5.00', $s['due_label']);
        $this->assertTrue($s['wallet']['covers']);
        $this->assertSame('₦20.00', $s['wallet']['balance_label']);
        $this->assertSame(['manual', 'paystack', 'payhub'], array_column($s['gateways'], 'slug'), 'a gateway without keys is not offered');
        $this->assertTrue($s['gateways'][2]['inline'], 'PayHub uses its inline popup');
        $this->assertSame('GTB 0123', $s['gateways'][0]['details']);
    }

    public function test_nothing_to_pay_for_other_states_or_other_clients(): void
    {
        $p = $this->payment(20.0);

        $this->assertNull($p->summary(self::request(['status' => 'queued']), self::client()));
        $this->assertNull($p->summary(self::request(['invoice_id' => null]), self::client()));
        $this->assertNull($p->summary(self::request(), self::client(['id' => 6])), "another client's invoice is never shown");
    }

    public function test_paying_from_the_wallet_settles_the_invoice_through_the_normal_payment_path(): void
    {
        $r = $this->payment(20.0)->payWithWallet(self::request(), self::client());

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue($r['paid']);
        $ledger = $this->write('/INSERT INTO client_credit_ledger/');
        $this->assertNotNull($ledger);
        $this->assertEquals(-5.0, $ledger['bindings'][1], 'exactly the amount due leaves the wallet');
        $tx = $this->write('/INSERT INTO transactions/');
        $this->assertSame('credit', $tx['bindings'][1]);
        $this->assertSame([77], $this->paidHooks, 'INVOICE_PAID fires, which queues the rename');
    }

    public function test_a_wallet_that_does_not_cover_the_fee_is_never_part_charged(): void
    {
        $p = $this->payment(3.0);
        $s = $p->summary(self::request(), self::client());

        $this->assertFalse($s['wallet']['covers']);
        $this->assertSame('₦2.00', $s['wallet']['short_label']);
        $this->assertSame('insufficient', $p->payWithWallet(self::request(), self::client())['code']);
        $this->assertSame([], $this->db->writes);
    }

    public function test_a_second_click_while_paying_is_refused(): void
    {
        $p = $this->payment(20.0, [], [], 0);

        $this->assertSame('busy', $p->payWithWallet(self::request(), self::client())['code']);
        $this->assertNull($this->write('/client_credit_ledger/'));
    }

    public function test_a_consolidated_fee_points_to_the_combined_invoice(): void
    {
        $p = $this->payment(20.0, ['parent_invoice_id' => 90], [['slug' => 'paystack', 'name' => 'Paystack', 'config' => json_encode(['secret_key' => 'sk'])]]);
        $s = $p->summary(self::request(), self::client());

        $this->assertTrue($s['consolidated']);
        $this->assertSame(90, $s['pay_invoice_id']);
        $this->assertSame([], $s['gateways']);
        $this->assertFalse($s['wallet']['covers']);
        $this->assertSame('consolidated', $p->payWithWallet(self::request(), self::client())['code']);
    }

    public function test_return_map_keeps_the_latest_ten(): void
    {
        $map = [];

        for ($i = 1; $i <= 12; $i++) {
            $map = UsernameChangePayment::withReturn($map, $i, '/client/services/' . $i);
        }
        $map = UsernameChangePayment::withReturn($map, 5, '/client/services/55');

        $this->assertCount(10, $map);
        $this->assertArrayNotHasKey(1, $map);
        $this->assertSame('/client/services/55', end($map));
    }

    public function test_gateway_callback_returns_to_the_remembered_local_page_only(): void
    {
        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        App::setContainer($container);
        $controller = (new \ReflectionClass(PaymentCallbackController::class))->newInstanceWithoutConstructor();
        $url = new ReflectionMethod(PaymentCallbackController::class, 'afterPaymentUrl');

        $_SESSION['pay_return'] = [77 => '/client/services/10#change-username', 78 => '//evil.example/x', 79 => 'https://evil.example', 80 => '/\\evil.example'];

        $this->assertSame('/client/services/10?payment=success#change-username', $url->invoke($controller, 77, 'success'));
        $this->assertSame('/client/services/10?payment=failed#change-username', $url->invoke($controller, 77, 'failed'));
        $this->assertSame('/client/invoices/78?payment=success', $url->invoke($controller, 78, 'success'), 'protocol-relative is refused');
        $this->assertSame('/client/invoices/79?payment=success', $url->invoke($controller, 79, 'success'));
        $this->assertSame('/client/invoices/80?payment=success', $url->invoke($controller, 80, 'success'));
        $this->assertSame('/client/invoices/81?payment=success', $url->invoke($controller, 81, 'success'), 'no entry: the invoice page as before');
    }

    public function test_routes_and_views_wire_the_pin_modal_and_pay_step(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/username-changer.php');
        $banner = (string) file_get_contents($root . '/resources/views/username-changer/banner.php');

        foreach (["'/client/services/{id}/username/pin/code'", "'/client/services/{id}/username/pin'", "'/client/services/{id}/username/pay'", "'/client/services/{id}/username/{rid}/pay/wallet'"] as $route) {
            $this->assertStringContainsString($route, $routes);
        }

        // The PIN modal and the Pay step hold forms, so neither may sit inside the request form.
        $formEnd = strpos($banner, '</form>');
        $this->assertGreaterThan($formEnd, strpos($banner, 'data-ucn-paystep'));
        $this->assertGreaterThan($formEnd, strpos($banner, 'data-ucn-pinm-form'));
    }

    // ------------------------------------------------------------ markup

    /** @return array<string, mixed> modal data as ClientUsernameController::modalData() builds it */
    public static function modal(array $over = []): array
    {
        return $over + [
            'service_id' => 10, 'current' => 'olduser', 'domain' => 'example.com', 'heading' => 'Change cPanel username', 'accent' => '',
            'rules' => (new UsernamePolicy())->toClientRules(), 'can_request' => true, 'blocked_reason' => null, 'remaining' => 1,
            'cooldown_days' => 30, 'approval' => false, 'allow_db_rename' => false, 'require_reason' => false, 'methods' => ['email', 'pin'],
            'fee' => '₦5.00', 'has_pin' => false, 'pin' => ['methods' => ['code', 'password'], 'email' => 'a••@example.com', 'min' => 4, 'max' => 12],
            'pay' => null, 'open' => null, 'suggestions' => [], 'history' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function paySummary(array $over = []): array
    {
        return $over + [
            'request_id' => 31, 'service_id' => 10, 'invoice_id' => 77, 'invoice_url' => '/client/invoices/77', 'invoice_status' => 'unpaid',
            'pay_invoice_id' => 77, 'consolidated' => false, 'total_label' => '₦5.00', 'paid_label' => '₦0.00', 'due' => 5.0, 'due_label' => '₦5.00',
            'wallet' => ['balance' => 20.0, 'balance_label' => '₦20.00', 'covers' => true, 'short_label' => null, 'add_funds_url' => '/client/wallet/add-funds'],
            'gateways' => [
                ['slug' => 'paystack', 'name' => 'Paystack', 'manual' => false, 'inline' => false, 'details' => null],
                ['slug' => 'payhub', 'name' => 'PayHub', 'manual' => false, 'inline' => true, 'details' => null],
                ['slug' => 'manual', 'name' => 'Bank transfer', 'manual' => true, 'inline' => false, 'details' => 'GTB <0123>'],
            ],
        ];
    }

    public static function renderView(string $template, array $data): string
    {
        $config = new Config(sys_get_temp_dir() . '/cv-ucn-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        try {
            return (new View(dirname(__DIR__, 2) . '/resources/views'))->render($template, $data);
        } finally {
            restore_error_handler();
        }
    }

    public function test_banner_without_a_pin_offers_to_set_one_and_shows_the_pay_step(): void
    {
        $html = self::renderView('username-changer.banner', ['ucn' => self::modal()]);

        $this->assertStringContainsString('data-ucn-pin-missing', $html);
        $this->assertStringContainsString('data-ucn-pin-open="missing"', $html);
        $this->assertStringContainsString('data-ucn-pinm', $html);
        $this->assertStringContainsString('action="/client/services/10/username/pin"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('data-ucn-step-dot="4"', $html, 'a Pay dot when a fee applies');
        $this->assertStringContainsString('a••@example.com', $html);

        $withPin = self::renderView('username-changer.banner', ['ucn' => self::modal(['has_pin' => true])]);
        $this->assertStringContainsString('data-ucn-pin-open="forgot"', $withPin);
        $this->assertStringNotContainsString('data-ucn-pin-missing', $withPin);

        $free = self::renderView('username-changer.banner', ['ucn' => self::modal(['fee' => null, 'pin' => null, 'has_pin' => true])]);
        $this->assertStringNotContainsString('data-ucn-step-dot="4"', $free);
        $this->assertStringNotContainsString('data-ucn-pinm', $free);
    }

    public function test_pay_panel_lists_wallet_and_gateways_and_escapes_bank_details(): void
    {
        $html = self::renderView('username-changer.pay-panel', ['pay' => self::paySummary()]);

        $this->assertStringContainsString('action="/client/services/10/username/31/pay/wallet"', $html);
        $this->assertStringContainsString('action="/client/invoices/77/pay/paystack"', $html);
        $this->assertStringContainsString('data-payhub-pay', $html);
        $this->assertStringContainsString('GTB &lt;0123&gt;', $html);
        $this->assertStringContainsString('₦5.00', $html);

        $short = self::renderView('username-changer.pay-panel', ['pay' => self::paySummary(['wallet' => ['balance' => 3.0, 'balance_label' => '₦3.00', 'covers' => false, 'short_label' => '₦2.00', 'add_funds_url' => '/client/wallet/add-funds']])]);
        $this->assertStringNotContainsString('/pay/wallet', $short);
        $this->assertStringContainsString('/client/wallet/add-funds', $short);
    }

    public function test_the_open_request_view_embeds_the_pay_panel(): void
    {
        $html = self::renderView('username-changer.banner', ['ucn' => self::modal([
            'can_request' => false, 'pay' => self::paySummary(),
            'open' => ['id' => 31, 'new' => 'newname', 'status' => 'awaiting_payment', 'label' => 'Awaiting payment', 'invoice_id' => 77, 'can_cancel' => true, 'can_resend' => false],
        ])]);

        $this->assertStringContainsString('/client/services/10/username/31/pay/wallet', $html);
        $this->assertStringNotContainsString('"gateways"', $html, 'payment details stay out of the embedded JSON');
    }
}
