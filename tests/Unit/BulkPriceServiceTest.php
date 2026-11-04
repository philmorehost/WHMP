<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\BulkPriceService;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Queue\SyncQueue;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

final class BulkPriceServiceTest extends DatabaseTestCase
{
    private BulkPriceService $service;
    private ProductGroupRepository $groups;
    private ProductRepository $products;
    private ProductPricingRepository $pricing;
    private DomainPricingRepository $domainPricing;
    private EmailLogRepository $emailLog;
    private string $mailLogPath;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->groups = new ProductGroupRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->pricing = new ProductPricingRepository($this->db);
        $this->domainPricing = new DomainPricingRepository($this->db);
        $this->emailLog = new EmailLogRepository($this->db);

        $this->mailLogPath = sys_get_temp_dir() . '/codevault-bulkprice-mail-' . uniqid() . '.log';

        // Same wiring EmailDispatcherTest uses: SendEmailJob resolves the
        // mailer via the App service locator when the SyncQueue runs inline.
        $container = new Container();
        $container->instance(Mailer::class, new LogMailer($this->mailLogPath));
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);

        $dispatcher = new EmailDispatcher(new \CodeVault\Mail\EmailTemplateRepository($this->db), new EmailLogRepository($this->db), new SyncQueue());

        $this->service = new BulkPriceService(
            $this->db,
            $this->products,
            $this->pricing,
            $this->domainPricing,
            $dispatcher
        );

        $this->groupId = $this->groups->create('Shared Hosting', null);
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLogPath);
        parent::tearDown();
    }

    private function product(string $name, array $pricing = []): int
    {
        $productId = $this->products->create([
            'product_group_id' => $this->groupId,
            'name' => $name,
            'description' => null,
            'status' => 'active',
        ]);

        foreach ($pricing as $cycle => [$setup, $price]) {
            $this->pricing->setPricing($productId, $cycle, $setup, $price);
        }

        return $productId;
    }

    private function client(string $email = 'buyer@example.test'): int
    {
        return (int) $this->db->insert(
            'INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$email, password_hash('secret123', PASSWORD_DEFAULT), 'Buy', 'Er', 'active']
        );
    }

    private function serviceRow(int $clientId, int $productId, string $cycle = 'monthly', string $status = 'active'): void
    {
        $this->db->insert(
            "INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at)
             VALUES (?, ?, 'Test Product', ?, 100.00, ?, DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW(), NOW())",
            [$clientId, $productId, $cycle, $status]
        );
    }

    private function domainRow(int $clientId, string $domain, string $tld = 'com', string $status = 'active'): void
    {
        $this->db->insert(
            'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$clientId, $domain, $tld, 'local', $status]
        );
    }

    public function test_percent_increase_updates_only_selected_products_and_cycles(): void
    {
        $hosting = $this->product('Starter Hosting', ['monthly' => [0.0, 100.0], 'annually' => [0.0, 1000.0]]);
        $other = $this->product('Other Hosting', ['monthly' => [0.0, 50.0]]);

        $result = $this->service->updateProducts([$hosting], 'percent', 10.0, 'increase', ['monthly'], false);

        $this->assertSame(100.0 * 1.10, $this->pricing->find($hosting, 'monthly')['price']);
        $this->assertSame(1000.0, $this->pricing->find($hosting, 'annually')['price'], 'unselected cycle is untouched');
        $this->assertSame(50.0, $this->pricing->find($other, 'monthly')['price'], 'unselected product is untouched');

        $this->assertCount(1, $result['changes']);
        $this->assertSame('price', $result['changes'][0]['field']);
        $this->assertSame(100.0, $result['changes'][0]['old']);
        $this->assertSame(110.0, $result['changes'][0]['new']);
        $this->assertSame('Starter Hosting', $result['changes'][0]['name']);
        $this->assertSame('Monthly', $result['changes'][0]['cycle_label']);
    }

    public function test_fixed_decrease_clamps_at_zero_and_keeps_setup_fee_unless_asked(): void
    {
        $cheap = $this->product('Cheap Plan', ['monthly' => [25.0, 5.0]]);

        $result = $this->service->updateProducts([$cheap], 'fixed', 50.0, 'decrease', ['monthly'], false);

        $row = $this->pricing->find($cheap, 'monthly');
        $this->assertSame(0.0, $row['price'], 'price never goes negative');
        $this->assertSame(25.0, $row['setup_fee'], 'setup fee untouched when includeSetup is false');

        $result = $this->service->updateProducts([$cheap], 'fixed', 5.0, 'decrease', ['monthly'], true);
        $row = $this->pricing->find($cheap, 'monthly');
        $this->assertSame(20.0, $row['setup_fee']);
        $this->assertCount(1, $result['changes'], 'price is already 0 so only the setup fee line moved');
        $this->assertSame('setup_fee', $result['changes'][0]['field']);
    }

    public function test_cycles_without_a_pricing_row_are_skipped_not_created(): void
    {
        $id = $this->product('Sparse Plan', ['monthly' => [0.0, 10.0]]);

        $result = $this->service->updateProducts([$id], 'percent', 50.0, 'increase', [], false);

        $this->assertSame(15.0, $this->pricing->find($id, 'monthly')['price']);
        $this->assertNull($this->pricing->find($id, 'annually'), 'no row must be invented for an unpriced cycle');
        $this->assertCount(1, $result['changes']);
    }

    public function test_domain_fields_update_only_selected_tlds_and_fields(): void
    {
        // Migration 0097 seeds .com/.net/.org/.com.ng/.ng — .com is 15/15/15.
        $result = $this->service->updateDomainPricing(['.com'], 'percent', 20.0, 'increase', ['register_price', 'renew_price']);

        $com = $this->domainPricing->findByTld('.com');
        $this->assertSame(18.0, (float) $com['register_price']);
        $this->assertSame(18.0, (float) $com['renew_price']);
        $this->assertSame(15.0, (float) $com['transfer_price'], 'unselected field is untouched');
        $this->assertSame(12.0, (float) $this->domainPricing->findByTld('.net')['register_price'], 'unselected TLD is untouched');

        $this->assertCount(2, $result['changes']);
        $this->assertSame('register_price', $result['changes'][0]['field']);
        $this->assertSame('Register', $result['changes'][0]['field_label']);

        // Bare form matches the same row (domains.tld has no leading dot).
        $this->service->updateDomainPricing(['com'], 'fixed', 1.0, 'decrease', ['register_price']);
        $this->assertSame(17.0, (float) $this->domainPricing->findByTld('.com')['register_price']);
    }

    public function test_unknown_tld_is_counted_as_skipped(): void
    {
        $result = $this->service->updateDomainPricing(['.doesnotexist'], 'percent', 10.0, 'increase', []);

        $this->assertSame([], $result['changes']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_subscribers_of_changed_products_and_tlds_get_one_price_review_email_each(): void
    {
        $hosting = $this->product('Starter Hosting', ['monthly' => [0.0, 100.0]]);
        $both = $this->client('both@example.test');
        $productOnly = $this->client('productonly@example.test');
        $domainOnly = $this->client('domainonly@example.test');
        $outsider = $this->client('outsider@example.test');

        $this->serviceRow($both, $hosting, 'monthly');
        $this->serviceRow($productOnly, $hosting, 'monthly');
        $this->domainRow($both, 'bothdomain.com', 'com');
        $this->domainRow($domainOnly, 'domainonly.com', 'com');
        $this->domainRow($outsider, 'outsider.org', 'org');

        $productResult = $this->service->updateProducts([$hosting], 'percent', 10.0, 'increase', ['monthly'], false);
        $domainResult = $this->service->updateDomainPricing(['.com'], 'percent', 10.0, 'increase', ['renew_price']);

        $notify = $this->service->notifySubscribers($productResult['changes'], $domainResult['changes'], [
            'services' => 'https://billing.test/client/services',
            'domains' => 'https://billing.test/client/domains',
        ]);

        $this->assertSame(3, $notify['notified'], 'product-only, domain-only and both-subscribers are notified; the outsider is not');
        $this->assertSame(0, $notify['no_impact']);

        $rows = $this->db->select("SELECT * FROM email_log WHERE template_key = 'price_update_notice'");
        $this->assertCount(3, $rows);

        $byEmail = [];
        foreach ($rows as $row) {
            $byEmail[$row['to_email']] = $row;
        }

        $this->assertArrayNotHasKey('outsider@example.test', $byEmail, 'a client with nothing affected gets no email');

        $bothBody = (string) $byEmail['both@example.test']['body_html'];
        $this->assertStringContainsString('Starter Hosting (Monthly): 100.00 &rarr; 110.00', $bothBody);
        $this->assertStringContainsString('<strong>bothdomain.com</strong> (.com): Renew 15.00 &rarr; 16.50', $bothBody);

        $productOnlyBody = (string) $byEmail['productonly@example.test']['body_html'];
        $this->assertStringContainsString('Starter Hosting (Monthly)', $productOnlyBody);
        $this->assertStringNotContainsString('domainonly', $productOnlyBody);
        $this->assertStringNotContainsString('Domain prices that affect you', $productOnlyBody);

        $domainOnlyBody = (string) $byEmail['domainonly@example.test']['body_html'];
        $this->assertStringContainsString('<strong>domainonly.com</strong> (.com)', $domainOnlyBody);
        $this->assertStringNotContainsString('Hosting / product prices', $domainOnlyBody);
    }

    public function test_subscriber_whose_own_cycle_did_not_change_is_not_emailed(): void
    {
        $hosting = $this->product('Starter Hosting', ['monthly' => [0.0, 100.0], 'annually' => [0.0, 1000.0]]);
        $yearly = $this->client('yearly@example.test');
        $this->serviceRow($yearly, $hosting, 'annually');

        $result = $this->service->updateProducts([$hosting], 'percent', 10.0, 'increase', ['monthly'], false);
        $notify = $this->service->notifySubscribers($result['changes'], []);

        $this->assertSame(0, $notify['notified']);
        $this->assertSame(1, $notify['no_impact'], 'subscribing to the product at an unchanged cycle is no personal impact');
        $this->assertSame(0, (int) $this->db->selectOne("SELECT COUNT(*) AS c FROM email_log WHERE template_key = 'price_update_notice'")['c']);
    }

    public function test_cancelled_subscribers_are_not_notified(): void
    {
        $hosting = $this->product('Starter Hosting', ['monthly' => [0.0, 100.0]]);
        $gone = $this->client('cancelled@example.test');
        $this->serviceRow($gone, $hosting, 'monthly', 'cancelled');

        $awayDomain = $this->client('transferred@example.test');
        $this->domainRow($awayDomain, 'gonedomain.com', 'com', 'transferred_away');

        $productResult = $this->service->updateProducts([$hosting], 'percent', 10.0, 'increase', ['monthly'], false);
        $domainResult = $this->service->updateDomainPricing(['.com'], 'percent', 10.0, 'increase', ['renew_price']);
        $notify = $this->service->notifySubscribers($productResult['changes'], $domainResult['changes']);

        $this->assertSame(0, $notify['notified']);
        $this->assertSame(2, $notify['no_impact']);
    }

    public function test_client_with_changed_setup_fee_only_still_gets_a_line(): void
    {
        $hosting = $this->product('Starter Hosting', ['monthly' => [50.0, 0.0]]);
        $client = $this->client('setuponly@example.test');
        $this->serviceRow($client, $hosting, 'monthly');

        // Price already 0 → a 10% increase moves only the setup fee.
        $result = $this->service->updateProducts([$hosting], 'percent', 10.0, 'increase', ['monthly'], true);
        $notify = $this->service->notifySubscribers($result['changes'], []);

        $this->assertSame(1, $notify['notified']);
        $body = (string) $this->db->selectOne("SELECT * FROM email_log WHERE template_key = 'price_update_notice'")['body_html'];
        $this->assertStringContainsString('setup 50.00 &rarr; 55.00', $body);
    }
}
