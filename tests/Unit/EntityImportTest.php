<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Import\CsvParser;
use CodeVault\Import\DomainPricingImportService;
use CodeVault\Import\InvoiceImportService;
use CodeVault\Import\ProductImportService;
use CodeVault\Import\ServiceImportService;
use CodeVault\Import\TransactionImportService;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * R29 — extends R16's CSV import engine beyond clients to services,
 * invoices, and transactions. Each importer gets its own section here
 * rather than being added to ImportTest.php, which stays scoped to
 * CsvParser/ClientImportService/ImportRunRepository as originally shipped.
 */
final class EntityImportTest extends DatabaseTestCase
{
    private CsvParser $parser;
    private ClientRepository $clients;
    private ProductRepository $products;
    private ServiceRepository $services;
    private InvoiceRepository $invoices;
    private TransactionRepository $transactions;
    private int $clientId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->parser = new CsvParser();
        $this->clients = new ClientRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->transactions = new TransactionRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'importee@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Import',
            'last_name' => 'Ee',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert('INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)', ['Group', $now, $now]);
        $this->productId = (int) $this->db->insert('INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$groupId, 'Starter VPS', $now, $now]);
    }

    // --- ServiceImportService ---------------------------------------------

    public function test_service_import_reports_missing_required_columns(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);

        $result = $importer->import(['client_email'], [['importee@example.test']]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_service_import_skips_an_unknown_client(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

        $result = $importer->import($headers, [['nobody@example.test', 'Starter VPS', 'monthly', '9.99', '2026-08-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('No client found', $result['errors'][0]['reason']);
    }

    public function test_service_import_skips_an_unknown_product(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

        $result = $importer->import($headers, [['importee@example.test', 'Does Not Exist', 'monthly', '9.99', '2026-08-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('No product found', $result['errors'][0]['reason']);
    }

    public function test_service_import_skips_an_invalid_billing_cycle(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

        $result = $importer->import($headers, [['importee@example.test', 'Starter VPS', 'fortnightly', '9.99', '2026-08-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid billing_cycle', $result['errors'][0]['reason']);
    }

    public function test_service_import_skips_an_invalid_next_due_date(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

        $result = $importer->import($headers, [['importee@example.test', 'Starter VPS', 'monthly', '9.99', '2026-02-30']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid next_due_date', $result['errors'][0]['reason']);
    }

    public function test_service_import_creates_a_real_service_and_defaults_status_to_pending(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

        $result = $importer->import($headers, [['importee@example.test', 'Starter VPS', 'monthly', '9.99', '2026-08-01']]);

        $this->assertSame(1, $result['imported']);
        $rows = $this->services->forClient($this->clientId);
        $this->assertCount(1, $rows);
        $this->assertSame($this->productId, (int) $rows[0]['product_id']);
        $this->assertSame('monthly', $rows[0]['billing_cycle']);
        $this->assertSame('9.99', $rows[0]['amount']);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('2026-08-01', $rows[0]['next_due_date']);
    }

    public function test_service_import_respects_an_explicit_status(): void
    {
        $importer = new ServiceImportService($this->clients, $this->products, $this->services);
        $headers = ['client_email', 'product_name', 'billing_cycle', 'amount', 'status', 'next_due_date'];

        $importer->import($headers, [['importee@example.test', 'Starter VPS', 'monthly', '9.99', 'active', '2026-08-01']]);

        $rows = $this->services->forClient($this->clientId);
        $this->assertSame('active', $rows[0]['status']);
    }

    // --- InvoiceImportService ----------------------------------------------

    public function test_invoice_import_reports_missing_required_columns(): void
    {
        $importer = new InvoiceImportService($this->clients, $this->invoices);

        $result = $importer->import(['client_email'], [['importee@example.test']]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_invoice_import_skips_an_unknown_client(): void
    {
        $importer = new InvoiceImportService($this->clients, $this->invoices);
        $headers = ['client_email', 'total', 'due_date'];

        $result = $importer->import($headers, [['nobody@example.test', '50.00', '2026-09-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('No client found', $result['errors'][0]['reason']);
    }

    public function test_invoice_import_skips_an_invalid_total(): void
    {
        $importer = new InvoiceImportService($this->clients, $this->invoices);
        $headers = ['client_email', 'total', 'due_date'];

        $result = $importer->import($headers, [['importee@example.test', 'not-a-number', '2026-09-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid total', $result['errors'][0]['reason']);
    }

    public function test_invoice_import_creates_a_real_invoice_with_a_summary_line_item_and_defaults_status_to_unpaid(): void
    {
        $importer = new InvoiceImportService($this->clients, $this->invoices);
        $headers = ['client_email', 'total', 'due_date'];

        $result = $importer->import($headers, [['importee@example.test', '100.00', '2026-09-01']]);

        $this->assertSame(1, $result['imported']);
        $rows = $this->invoices->forClient($this->clientId);
        $this->assertCount(1, $rows);
        $this->assertSame('unpaid', $rows[0]['status']);
        $this->assertSame('100.00', $rows[0]['total']);
        $this->assertNull($rows[0]['paid_at']);

        $items = $this->invoices->items((int) $rows[0]['id']);
        $this->assertCount(1, $items);
        $this->assertSame('Imported invoice', $items[0]['description']);
    }

    public function test_invoice_import_preserves_an_explicit_paid_status_total_and_paid_at(): void
    {
        $importer = new InvoiceImportService($this->clients, $this->invoices);
        $headers = ['client_email', 'status', 'total', 'tax_amount', 'due_date', 'paid_at'];

        $importer->import($headers, [['importee@example.test', 'paid', '120.00', '10.00', '2026-01-15', '2026-01-10']]);

        $rows = $this->invoices->forClient($this->clientId);
        $this->assertSame('paid', $rows[0]['status']);
        $this->assertSame('120.00', $rows[0]['total']);
        $this->assertSame('10.00', $rows[0]['tax_amount']);
        $this->assertSame('110.00', $rows[0]['subtotal']);
        $this->assertSame('2026-01-10 00:00:00', $rows[0]['paid_at']);
    }

    // --- TransactionImportService --------------------------------------------

    public function test_transaction_import_reports_missing_required_columns(): void
    {
        $importer = new TransactionImportService($this->invoices, $this->transactions);

        $result = $importer->import(['invoice_id'], [['1']]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_transaction_import_skips_a_nonexistent_invoice_id(): void
    {
        $importer = new TransactionImportService($this->invoices, $this->transactions);
        $headers = ['invoice_id', 'amount', 'created_at'];

        $result = $importer->import($headers, [['999999', '50.00', '2026-01-10']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('No invoice found', $result['errors'][0]['reason']);
    }

    public function test_transaction_import_skips_a_non_numeric_invoice_id(): void
    {
        $importer = new TransactionImportService($this->invoices, $this->transactions);
        $headers = ['invoice_id', 'amount', 'created_at'];

        $result = $importer->import($headers, [['not-an-id', '50.00', '2026-01-10']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid invoice_id', $result['errors'][0]['reason']);
    }

    public function test_transaction_import_creates_a_real_transaction_preserving_the_historical_created_at(): void
    {
        $invoiceId = $this->invoices->createHistorical($this->clientId, 'paid', 120.00, 10.00, '2026-01-15', '2026-01-10 00:00:00');

        $importer = new TransactionImportService($this->invoices, $this->transactions);
        $headers = ['invoice_id', 'gateway_slug', 'amount', 'status', 'gateway_transaction_id', 'created_at'];

        $result = $importer->import($headers, [[(string) $invoiceId, 'paystack', '120.00', 'completed', 'TX-001', '2026-01-10']]);

        $this->assertSame(1, $result['imported']);
        $rows = $this->transactions->forInvoice($invoiceId);
        $this->assertCount(1, $rows);
        $this->assertSame('paystack', $rows[0]['gateway_slug']);
        $this->assertSame('TX-001', $rows[0]['gateway_transaction_id']);
        $this->assertSame('2026-01-10 00:00:00', $rows[0]['created_at'], 'the historical date must be preserved, not replaced with "now"');
    }

    public function test_transaction_import_defaults_gateway_slug_to_manual_and_status_to_completed(): void
    {
        $invoiceId = $this->invoices->createHistorical($this->clientId, 'paid', 50.00, 0.0, '2026-01-15', '2026-01-10 00:00:00');

        $importer = new TransactionImportService($this->invoices, $this->transactions);
        $headers = ['invoice_id', 'amount', 'created_at'];

        $importer->import($headers, [[(string) $invoiceId, '50.00', '2026-01-10']]);

        $rows = $this->transactions->forInvoice($invoiceId);
        $this->assertSame('manual', $rows[0]['gateway_slug']);
        $this->assertSame('completed', $rows[0]['status']);
    }

    // --- ProductImportService -----------------------------------------------

    public function test_product_import_reports_missing_required_columns(): void
    {
        $importer = new ProductImportService($this->products, new ProductGroupRepository($this->db), new ProductPricingRepository($this->db));

        $result = $importer->import(['name'], [['Solo VPS']]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_product_import_creates_a_new_group_and_product_with_pricing(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $pricing = new ProductPricingRepository($this->db);
        $importer = new ProductImportService($this->products, $groups, $pricing);
        $headers = ['name', 'description', 'group', 'monthly_price', 'annually_price', 'setup_fee'];

        $result = $importer->import($headers, [
            ['Imported VPS', 'A fresh VPS plan', 'New Hosting Group', '9.99', '99.00', '5.00'],
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertNotNull($groups->findByName('New Hosting Group'));

        $product = $this->products->findByName('Imported VPS');
        $this->assertNotNull($product);
        $this->assertSame('active', $product['status']);

        $productPricing = $pricing->forProduct((int) $product['id']);
        $this->assertSame('9.99', $productPricing['monthly']['price']);
        $this->assertSame('5.00', $productPricing['monthly']['setup_fee']);
        $this->assertSame('99.00', $productPricing['annually']['price']);
    }

    public function test_product_import_updates_an_existing_product_by_name_without_duplicating(): void
    {
        $importer = new ProductImportService($this->products, new ProductGroupRepository($this->db), new ProductPricingRepository($this->db));
        $headers = ['name', 'group', 'status'];

        $importer->import($headers, [['Starter VPS', 'Group', 'hidden']]);

        $this->assertSame('hidden', $this->products->findByName('Starter VPS')['status']);
        $all = $this->products->all();
        $this->assertCount(1, array_filter($all, static fn (array $p) => $p['name'] === 'Starter VPS'));
    }

    public function test_product_import_skips_an_invalid_price(): void
    {
        $importer = new ProductImportService($this->products, new ProductGroupRepository($this->db), new ProductPricingRepository($this->db));
        $headers = ['name', 'group', 'monthly_price'];

        $result = $importer->import($headers, [['Broken Plan', 'Group', 'not-a-number']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid monthly_price', $result['errors'][0]['reason']);
    }

    // --- DomainPricingImportService ------------------------------------------

    public function test_tld_import_reports_missing_required_columns(): void
    {
        $importer = new DomainPricingImportService(new DomainPricingRepository($this->db));

        $result = $importer->import(['tld'], [['.com']]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_tld_import_creates_and_then_upserts_pricing_by_tld(): void
    {
        // database/migrations seeds .com/.net/.org/.com.ng/.ng by default —
        // use a TLD outside that seed list so this test actually exercises
        // the create path, not an update of pre-existing seed data.
        $domainPricing = new DomainPricingRepository($this->db);
        $importer = new DomainPricingImportService($domainPricing);
        $headers = ['tld', 'registrar_slug', 'register_price', 'transfer_price', 'renew_price'];
        $countBefore = count($domainPricing->all());

        $result = $importer->import($headers, [['xyz', 'local', '10.00', '9.00', '11.00']]);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('10.00', $domainPricing->findByTld('xyz')['register_price']);
        $this->assertCount($countBefore + 1, $domainPricing->all());

        // Re-importing an updated price for the same TLD updates, doesn't duplicate.
        $importer->import($headers, [['.xyz', 'local', '12.00', '9.00', '11.00']]);
        $this->assertSame('12.00', $domainPricing->findByTld('xyz')['register_price']);
        $this->assertCount($countBefore + 1, $domainPricing->all());
    }

    public function test_tld_import_skips_an_invalid_price(): void
    {
        $importer = new DomainPricingImportService(new DomainPricingRepository($this->db));
        $headers = ['tld', 'registrar_slug', 'register_price', 'transfer_price', 'renew_price'];

        $result = $importer->import($headers, [['.net', 'local', 'free', '9.00', '11.00']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Invalid register_price', $result['errors'][0]['reason']);
    }
}
