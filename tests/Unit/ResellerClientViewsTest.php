<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The reseller's Customers pages render with the data ClientResellerClientsController
 * passes, show each action only where ResellerClientManager allows it, and never leak
 * a raw value unescaped. Any undefined index or bad type fails here as an exception
 * instead of as a broken page.
 */
final class ResellerClientViewsTest extends TestCase
{
    private View $view;
    private ?string $savedAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://platform.test';
        $_SERVER['REQUEST_URI'] = '/client/reseller/clients';

        $config = new Config(sys_get_temp_dir() . '/codevault-views-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }

        parent::tearDown();
    }

    public function test_the_list_shows_customers_with_their_counts_and_actions(): void
    {
        $html = $this->view->render('reseller.client-clients', [
            'store' => ['id' => 7, 'brand_name' => 'Acme <Hosting>', 'status' => 'active'],
            'summary' => ['customers' => 2, 'services_active' => 3, 'services_suspended' => 1, 'domains' => 4, 'invoices_unpaid' => 1],
            'results' => ['data' => [
                $this->listRow(['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Obi', 'services_suspended' => 1, 'invoices_unpaid' => 1]),
                $this->listRow(['id' => 43, 'first_name' => 'Bo', 'last_name' => 'Ng', 'status' => 'closed']),
            ], 'total' => 30, 'page' => 1, 'perPage' => 25],
            'search' => 'a"b',
            'filter' => 'unpaid',
            'storeError' => null,
            'notice' => 'Saved.',
            'error' => null,
        ]);

        $this->assertStringContainsString('Acme &lt;Hosting&gt;', $html);
        $this->assertStringNotContainsString('Acme <Hosting>', $html);
        $this->assertStringContainsString('/client/reseller/clients/42', $html);
        $this->assertStringContainsString('action="/client/reseller/clients/42/login"', $html);
        $this->assertStringNotContainsString('action="/client/reseller/clients/43/login"', $html, 'a closed account cannot be signed in to');
        $this->assertStringContainsString('1 suspended', $html);
        $this->assertStringContainsString('value="a&quot;b"', $html);
        $this->assertStringContainsString('Page 1 of 2', $html);
        $this->assertStringContainsString('rs-nav__link--active', $html);
        $this->assertStringContainsString('Saved.', $html);
    }

    public function test_the_list_explains_an_empty_store(): void
    {
        $html = $this->view->render('reseller.client-clients', [
            'store' => ['id' => 7, 'brand_name' => 'Acme', 'status' => 'active'],
            'summary' => ['customers' => 0, 'services_active' => 0, 'services_suspended' => 0, 'domains' => 0, 'invoices_unpaid' => 0],
            'results' => ['data' => [], 'total' => 0, 'page' => 1, 'perPage' => 25],
            'search' => '',
            'filter' => 'all',
            'storeError' => null,
            'notice' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('No customers yet', $html);
    }

    public function test_the_customer_page_offers_only_the_allowed_actions(): void
    {
        $html = $this->view->render('reseller.client-client', $this->customerPage());

        // Active service: suspend and terminate. Suspended by this store: unsuspend.
        $this->assertStringContainsString('/services/1/suspend', $html);
        $this->assertStringContainsString('/services/1/terminate', $html);
        $this->assertStringContainsString('/services/2/unsuspend', $html);
        // Suspended by the platform: no unsuspend button, a pointer to support instead.
        $this->assertStringNotContainsString('/services/3/unsuspend', $html);
        $this->assertStringContainsString('Contact support to lift', $html);
        // Terminated: nothing to do.
        $this->assertStringNotContainsString('/services/4/', $html);

        // Active domain: lock, nameservers and auto-renew; the current nameservers prefilled.
        $this->assertStringContainsString('/domains/9/lock', $html);
        $this->assertStringContainsString('/domains/9/nameservers', $html);
        $this->assertStringContainsString('/domains/9/auto-renew', $html);
        $this->assertStringContainsString('value="ns1.acme.test"', $html);
        // Expired domain: no lock or nameserver changes.
        $this->assertStringNotContainsString('/domains/10/lock', $html);

        $this->assertStringContainsString('action="/client/reseller/clients/42/login"', $html);
        $this->assertStringContainsString('/client/reseller/clients/42/password-reset', $html);
        $this->assertStringContainsString('name="first_name"', $html);
        $this->assertStringNotContainsString('name="email"', $html, 'resellers cannot change the login email');
        $this->assertStringContainsString('/client/reseller/tickets/77', $html);
        $this->assertStringContainsString('NGN 5,000.00', $html);
        $this->assertSame(
            substr_count($html, '<form method="post"'),
            substr_count($html, 'name="_token"'),
            'every form that changes something carries the CSRF token'
        );
    }

    public function test_a_suspended_store_sees_its_customers_but_cannot_change_them(): void
    {
        $data = $this->customerPage();
        $data['store']['status'] = 'suspended';
        $data['storeError'] = 'Your store is suspended.';

        $html = $this->view->render('reseller.client-client', $data);

        $this->assertStringContainsString('Your store is suspended.', $html);
        $this->assertStringNotContainsString('/services/1/suspend', $html);
        $this->assertStringNotContainsString('/clients/42/login"', $html);
        $this->assertStringContainsString('<fieldset disabled', $html);
    }

    /** @param array<string, mixed> $over */
    private function listRow(array $over): array
    {
        return array_merge([
            'id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'company_name' => null,
            'status' => 'active', 'created_at' => '2026-09-01 10:00:00',
            'services_total' => 2, 'services_active' => 1, 'services_suspended' => 0, 'domains_total' => 1, 'invoices_unpaid' => 0,
        ], $over);
    }

    /** @return array<string, mixed> */
    private function customerPage(): array
    {
        $service = static fn (int $id, string $status, ?int $by = null): array => [
            'id' => $id, 'client_id' => 42, 'product_name' => 'Starter Hosting', 'domain' => 'ada.test', 'hostname' => null,
            'username' => 'ada' . $id, 'server_id' => 1, 'status' => $status, 'suspension_reason' => $status === 'suspended' ? 'Unpaid' : null,
            'suspended_by_reseller_id' => $by, 'amount' => '5000.00', 'billing_cycle' => 'monthly', 'next_due_date' => '2026-11-01',
        ];
        $domain = static fn (int $id, string $status): array => [
            'id' => $id, 'client_id' => 42, 'domain_name' => "d{$id}.test", 'status' => $status, 'expiry_date' => '2027-01-01',
            'auto_renew' => 1, 'registrar_lock_enabled' => 1, 'nameservers' => json_encode(['ns1.acme.test', 'ns2.acme.test']),
        ];

        return [
            'store' => ['id' => 7, 'brand_name' => 'Acme', 'status' => 'active'],
            'client' => [
                'id' => 42, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.test', 'company_name' => 'Obi & Co',
                'phone' => null, 'address1' => null, 'address2' => null, 'city' => 'Lagos', 'state' => null, 'postcode' => null,
                'country' => 'NG', 'status' => 'active', 'created_at' => '2026-09-01 10:00:00',
            ],
            'services' => [$service(1, 'active'), $service(2, 'suspended', 7), $service(3, 'suspended'), $service(4, 'terminated')],
            'domains' => [$domain(9, 'active'), $domain(10, 'expired')],
            'invoices' => [['id' => 5, 'due_date' => '2026-10-01', 'total' => '5000.00', 'status' => 'unpaid', 'currency_id' => null, 'currency_rate' => 1]],
            'tickets' => [['id' => 77, 'subject' => 'Help', 'status' => 'open', 'updated_at' => '2026-10-02 09:00:00']],
            'serviceMoney' => static fn (float $amount): string => 'NGN ' . number_format($amount, 2),
            'invoiceMoney' => static fn (array $invoice): string => 'NGN ' . number_format((float) $invoice['total'], 2),
            'storeError' => null,
            'notice' => null,
            'error' => null,
        ];
    }
}
