<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Catalog\ConfigurableOptionGroupRepository;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Import\WhmcsImportService;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use PDO;

/**
 * Exercises WhmcsImportService against a real second MariaDB schema built
 * to look like a genuine WHMCS install (tblclients/tblinvoices/tblaccounts
 * etc.) — the same "real SQL, not mocks" testing style DatabaseTestCase
 * uses elsewhere, since this class's whole job is orchestrating real PDO
 * queries against an external schema.
 */
final class WhmcsImportServiceTest extends DatabaseTestCase
{
    private const REMOTE_DB = 'codevault_test_whmcs_remote';

    private PDO $remote;
    private WhmcsImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $bootstrap = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $bootstrap->exec('DROP DATABASE IF EXISTS ' . self::REMOTE_DB);
        $bootstrap->exec('CREATE DATABASE ' . self::REMOTE_DB);

        $this->remote = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::REMOTE_DB, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->remote->exec(
            <<<'SQL'
            CREATE TABLE tblclients (
                id INT PRIMARY KEY, email VARCHAR(191), firstname VARCHAR(100), lastname VARCHAR(100),
                companyname VARCHAR(191), address1 VARCHAR(191), address2 VARCHAR(191), city VARCHAR(100),
                state VARCHAR(100), postcode VARCHAR(20), country VARCHAR(10), phonenumber VARCHAR(40),
                password VARCHAR(255), status VARCHAR(20), datecreated DATETIME
            )
            SQL
        );
        $this->remote->exec(
            <<<'SQL'
            CREATE TABLE tblinvoices (
                id INT PRIMARY KEY, userid INT, invoicenum VARCHAR(50), subtotal DECIMAL(10,2), tax DECIMAL(10,2),
                tax2 DECIMAL(10,2), total DECIMAL(10,2), status VARCHAR(20), date DATE, duedate DATE, datepaid DATETIME NULL
            )
            SQL
        );
        $this->remote->exec(
            <<<'SQL'
            CREATE TABLE tblaccounts (
                id INT PRIMARY KEY, userid INT, invoiceid INT, gateway VARCHAR(50), amountin DECIMAL(10,2),
                amountout DECIMAL(10,2), transid VARCHAR(191), date DATETIME
            )
            SQL
        );
        // Empty stand-ins for the other steps' source tables — this test
        // class only exercises clients/invoices/transactions, but the
        // migrator's other steps still run against this schema and would
        // otherwise fail with "table doesn't exist" noise in every result.
        $this->remote->exec('CREATE TABLE tblservers (id INT PRIMARY KEY)');
        $this->remote->exec('CREATE TABLE tblproducts (id INT PRIMARY KEY, name VARCHAR(191), description TEXT, hidden TINYINT DEFAULT 0)');
        $this->remote->exec('CREATE TABLE tblhosting (id INT PRIMARY KEY)');
        $this->remote->exec('CREATE TABLE tbldomains (id INT PRIMARY KEY)');
        $this->remote->exec('CREATE TABLE tblcurrencies (id INT PRIMARY KEY, code VARCHAR(3), prefix VARCHAR(5), suffix VARCHAR(5), rate DECIMAL(10,4))');
        $this->remote->exec('CREATE TABLE tbltaxrules (id INT PRIMARY KEY, country VARCHAR(2), state VARCHAR(100), name VARCHAR(100), taxrate DECIMAL(5,2))');
        $this->remote->exec('CREATE TABLE tblcontacts (id INT PRIMARY KEY, userid INT, firstname VARCHAR(100), lastname VARCHAR(100), email VARCHAR(191), permissions VARCHAR(255))');
        $this->remote->exec('CREATE TABLE tblproductconfiggroups (id INT PRIMARY KEY, name VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblproductconfiglinks (gid INT, pid INT)');
        $this->remote->exec('CREATE TABLE tblproductconfigoptions (id INT PRIMARY KEY, gid INT, optionname VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblproductconfigoptionssub (id INT PRIMARY KEY, configid INT, optionname VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblpricing (id INT PRIMARY KEY, type VARCHAR(20), relid INT, monthly DECIMAL(10,2), quarterly DECIMAL(10,2), semiannually DECIMAL(10,2), annually DECIMAL(10,2), biennially DECIMAL(10,2), triennially DECIMAL(10,2))');
        $this->remote->exec('CREATE TABLE tbldepartments (id INT PRIMARY KEY, name VARCHAR(191), email VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tbltickets (id INT PRIMARY KEY, did INT, userid INT, email VARCHAR(191), name VARCHAR(191), subject VARCHAR(255), status VARCHAR(30), priority VARCHAR(20), date DATETIME)');
        $this->remote->exec('CREATE TABLE tblticketposts (id INT PRIMARY KEY, ticketid INT, message TEXT, name VARCHAR(191), admin VARCHAR(191), date DATETIME)');
        $this->remote->exec('CREATE TABLE tblpromotions (id INT PRIMARY KEY, code VARCHAR(50), type VARCHAR(20), value DECIMAL(10,2), maxuses INT, uses INT, startdate DATE, expirydate DATE, status VARCHAR(20))');

        $this->importer = new WhmcsImportService($this->db);
    }

    protected function tearDown(): void
    {
        $bootstrap = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $bootstrap->exec('DROP DATABASE IF EXISTS ' . self::REMOTE_DB);
        parent::tearDown();
    }

    private function credentials(): array
    {
        return ['host' => '127.0.0.1', 'port' => 3306, 'database' => self::REMOTE_DB, 'username' => 'root', 'password' => '', 'prefix' => ''];
    }

    public function test_import_fails_gracefully_against_unreachable_credentials(): void
    {
        $result = $this->importer->import(['host' => '127.0.0.1', 'port' => 3306, 'database' => 'does_not_exist_db', 'username' => 'root', 'password' => '', 'prefix' => '']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to connect', $result['message']);
    }

    public function test_import_migrates_clients_and_invoices(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, companyname, phonenumber, status, datecreated) VALUES (1, 'legacy@example.test', 'Legacy', 'Client', 'Old Co', '+1-555-0100', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, tax2, total, status, date, duedate, datepaid) VALUES (10, 1, 'INV-0010', 90.00, 10.00, 0.00, 100.00, 'Paid', '2020-02-01', '2020-02-10', '2020-02-05 00:00:00')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['imported']['clients']);
        $this->assertSame(1, $result['imported']['invoices']);

        $clients = new ClientRepository($this->db);
        $client = $clients->findByEmail('legacy@example.test');
        $this->assertNotNull($client);
        $this->assertSame('+1-555-0100', $client['phone']);
        $this->assertSame('active', $client['status']);

        $invoices = new InvoiceRepository($this->db);
        $invoice = $invoices->forClient((int) $client['id'])[0];
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('100.00', $invoice['total']);
    }

    public function test_import_migrates_transactions_keyed_to_the_remapped_invoice(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'payer@example.test', 'Pay', 'Er', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, tax2, total, status, date, duedate, datepaid) VALUES (10, 1, 'INV-0010', 90.00, 10.00, 0.00, 100.00, 'Paid', '2020-02-01', '2020-02-10', '2020-02-05 00:00:00')");
        $this->remote->exec("INSERT INTO tblaccounts (id, userid, invoiceid, gateway, amountin, amountout, transid, date) VALUES (1, 1, 10, 'paypal', 100.00, 0.00, 'TXN-100', '2020-02-05 12:00:00')");
        // A refund entry (amountout > 0) and a transaction for an invoice that doesn't exist remotely.
        $this->remote->exec("INSERT INTO tblaccounts (id, userid, invoiceid, gateway, amountin, amountout, transid, date) VALUES (2, 1, 10, 'paypal', 0.00, 25.00, 'TXN-REFUND', '2020-03-01 12:00:00')");
        $this->remote->exec("INSERT INTO tblaccounts (id, userid, invoiceid, gateway, amountin, amountout, transid, date) VALUES (3, 1, 9999, 'paypal', 50.00, 0.00, 'TXN-ORPHAN', '2020-03-01 12:00:00')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        // The orphaned transaction (invoice 9999 was never in tblinvoices) is silently skipped, not an import failure.
        $this->assertSame(2, $result['imported']['transactions']);

        $clients = new ClientRepository($this->db);
        $localClientId = (int) $clients->findByEmail('payer@example.test')['id'];
        $invoices = new InvoiceRepository($this->db);
        $localInvoiceId = (int) $invoices->forClient($localClientId)[0]['id'];
        $transactions = new TransactionRepository($this->db);
        $rows = $transactions->forInvoice($localInvoiceId);

        $this->assertCount(2, $rows);
        $paymentRow = array_values(array_filter($rows, static fn (array $r) => $r['gateway_transaction_id'] === 'TXN-100'))[0];
        $this->assertSame('completed', $paymentRow['status']);
        $this->assertSame('100.00', $paymentRow['amount']);

        $refundRow = array_values(array_filter($rows, static fn (array $r) => $r['gateway_transaction_id'] === 'TXN-REFUND'))[0];
        $this->assertSame('refunded', $refundRow['status']);
        $this->assertSame('25.00', $refundRow['amount']);
    }

    public function test_import_creates_new_currencies_and_updates_the_existing_default(): void
    {
        // USD already exists locally (seeded by migration 0037) — the
        // import should update it in place, not duplicate it.
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate) VALUES (1, 'USD', '$', '', 1.0000)");
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate) VALUES (2, 'EUR', '', ' EUR', 0.9200)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']['currencies']);

        $currencies = new CurrencyRepository($this->db);
        $this->assertCount(2, $currencies->all());
        $eur = $currencies->findByCode('EUR');
        $this->assertNotNull($eur);
        $this->assertSame('0.9200', $eur['exchange_rate']);
        $this->assertSame('EUR', $eur['symbol'], 'the suffix is trimmed of surrounding whitespace');
    }

    public function test_import_creates_tax_rules_and_skips_rows_with_no_country(): void
    {
        $this->remote->exec("INSERT INTO tbltaxrules (id, country, state, name, taxrate) VALUES (1, 'US', NULL, 'Sales Tax', 7.25)");
        $this->remote->exec("INSERT INTO tbltaxrules (id, country, state, name, taxrate) VALUES (2, 'US', 'CA', 'CA State Tax', 8.50)");
        $this->remote->exec("INSERT INTO tbltaxrules (id, country, state, name, taxrate) VALUES (3, NULL, NULL, 'Bad Row', 5.00)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']['tax_rules']);

        $taxRules = new TaxRuleRepository($this->db);
        $countryWide = $taxRules->resolve('US', null);
        $this->assertSame('7.25', $countryWide['rate']);

        $stateSpecific = $taxRules->resolve('US', 'CA');
        $this->assertSame('8.50', $stateSpecific['rate']);
    }

    public function test_import_migrates_contacts_and_skips_a_contact_for_an_unmigrated_client(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'owner@example.test', 'Owner', 'Er', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblcontacts (id, userid, firstname, lastname, email, permissions) VALUES (1, 1, 'Sub', 'Account', 'sub@example.test', 'general,support')");
        // References a client that was never in tblclients — should be skipped, not error out the whole step.
        $this->remote->exec("INSERT INTO tblcontacts (id, userid, firstname, lastname, email, permissions) VALUES (2, 999, 'Ghost', 'User', 'ghost@example.test', '')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['imported']['contacts']);

        $clients = new ClientRepository($this->db);
        $ownerId = (int) $clients->findByEmail('owner@example.test')['id'];
        $contacts = new ClientContactRepository($this->db);
        $rows = $contacts->forClient($ownerId);

        $this->assertCount(1, $rows);
        $this->assertSame('Sub Account', $rows[0]['name']);
        $this->assertSame('sub@example.test', $rows[0]['email']);
        $this->assertSame(['general', 'support'], json_decode($rows[0]['permissions'], true));
    }

    public function test_import_does_not_duplicate_a_contact_already_migrated(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'owner2@example.test', 'Owner', 'Two', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblcontacts (id, userid, firstname, lastname, email, permissions) VALUES (1, 1, 'Sub', 'Account', 'sub2@example.test', '')");

        $this->importer->import($this->credentials());
        $this->importer->import($this->credentials());

        $clients = new ClientRepository($this->db);
        $ownerId = (int) $clients->findByEmail('owner2@example.test')['id'];
        $contacts = new ClientContactRepository($this->db);

        $this->assertCount(1, $contacts->forClient($ownerId));
    }

    public function test_import_migrates_configurable_option_groups_options_and_pricing(): void
    {
        $this->remote->exec("INSERT INTO tblproducts (id, name, description, hidden) VALUES (1, 'VPS Plan', 'A VPS plan', 0)");
        $this->remote->exec("INSERT INTO tblproductconfiggroups (id, name) VALUES (1, 'Extra Resources')");
        $this->remote->exec("INSERT INTO tblproductconfiglinks (gid, pid) VALUES (1, 1)");
        $this->remote->exec("INSERT INTO tblproductconfigoptions (id, gid, optionname) VALUES (1, 1, 'RAM')");
        $this->remote->exec("INSERT INTO tblproductconfigoptionssub (id, configid, optionname) VALUES (1, 1, '2GB')");
        $this->remote->exec("INSERT INTO tblproductconfigoptionssub (id, configid, optionname) VALUES (2, 1, '4GB')");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, monthly, annually) VALUES (1, 'configoptions', 1, 5.00, 50.00)");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, monthly, annually) VALUES (2, 'configoptions', 2, 9.00, 90.00)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']['configurable_options']);

        $groups = new ConfigurableOptionGroupRepository($this->db);
        $group = $groups->all()[0];
        $this->assertSame('Extra Resources', $group['name']);

        $products = new ProductRepository($this->db);
        $product = $products->findByName('VPS Plan');
        $this->assertContains((int) $group['id'], $groups->idsForProduct((int) $product['id']));

        $options = new ConfigurableOptionRepository($this->db);
        $groupOptions = $options->forGroup((int) $group['id']);
        $this->assertCount(2, $groupOptions);
        $names = array_column($groupOptions, 'name');
        $this->assertContains('RAM - 2GB', $names);
        $this->assertContains('RAM - 4GB', $names);

        $pricing = new ConfigurableOptionPricingRepository($this->db);
        $twoGbOption = current(array_filter($groupOptions, static fn (array $o) => $o['name'] === 'RAM - 2GB'));
        $this->assertSame(5.0, $pricing->priceFor((int) $twoGbOption['id'], 'monthly'));
        $this->assertSame(50.0, $pricing->priceFor((int) $twoGbOption['id'], 'annually'));
    }

    public function test_import_configurable_options_is_safe_to_re_run(): void
    {
        $this->remote->exec("INSERT INTO tblproducts (id, name, description, hidden) VALUES (1, 'VPS Plan', 'A VPS plan', 0)");
        $this->remote->exec("INSERT INTO tblproductconfiggroups (id, name) VALUES (1, 'Extra Resources')");
        $this->remote->exec("INSERT INTO tblproductconfiglinks (gid, pid) VALUES (1, 1)");
        $this->remote->exec("INSERT INTO tblproductconfigoptions (id, gid, optionname) VALUES (1, 1, 'RAM')");
        $this->remote->exec("INSERT INTO tblproductconfigoptionssub (id, configid, optionname) VALUES (1, 1, '2GB')");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, monthly) VALUES (1, 'configoptions', 1, 5.00)");

        $this->importer->import($this->credentials());
        $this->importer->import($this->credentials());

        $groups = new ConfigurableOptionGroupRepository($this->db);
        $this->assertCount(1, $groups->all());
        $options = new ConfigurableOptionRepository($this->db);
        $this->assertCount(1, $options->forGroup((int) $groups->all()[0]['id']));
    }

    public function test_import_migrates_departments_tickets_and_replies(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'ticketowner@example.test', 'Ticket', 'Owner', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tbldepartments (id, name, email) VALUES (1, 'Sales', 'sales@example.test')");
        $this->remote->exec("INSERT INTO tbltickets (id, did, userid, email, name, subject, status, priority, date) VALUES (100, 1, 1, 'ticketowner@example.test', 'Ticket Owner', 'Help with billing', 'Open', 'High', '2021-01-01 10:00:00')");
        $this->remote->exec("INSERT INTO tblticketposts (id, ticketid, message, name, admin, date) VALUES (1, 100, 'Please help', 'Ticket Owner', '', '2021-01-01 10:00:00')");
        $this->remote->exec("INSERT INTO tblticketposts (id, ticketid, message, name, admin, date) VALUES (2, 100, 'Sure, one moment', '', 'SupportAgent', '2021-01-01 11:00:00')");
        // A ticket for a department that never migrated — must be skipped, not error the whole step.
        $this->remote->exec("INSERT INTO tbltickets (id, did, userid, email, name, subject, status, priority, date) VALUES (101, 999, 1, 'ticketowner@example.test', 'Ticket Owner', 'Orphan dept', 'Open', 'Low', '2021-01-01 10:00:00')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['imported']['departments']);
        $this->assertSame(1, $result['imported']['tickets']);

        $departments = new DepartmentRepository($this->db);
        $department = $departments->findByEmail('sales@example.test');
        $this->assertNotNull($department);
        $this->assertSame('Sales', $department['name']);

        $tickets = new TicketRepository($this->db);
        $clients = new ClientRepository($this->db);
        $clientId = (int) $clients->findByEmail('ticketowner@example.test')['id'];
        $ticketRows = $tickets->forClient($clientId);
        $this->assertCount(1, $ticketRows);
        $this->assertSame('Help with billing', $ticketRows[0]['subject']);
        $this->assertSame('open', $ticketRows[0]['status']);
        $this->assertSame('high', $ticketRows[0]['priority']);
        $this->assertSame((int) $department['id'], (int) $ticketRows[0]['department_id']);

        $replies = new TicketReplyRepository($this->db);
        $replyRows = $replies->forTicket((int) $ticketRows[0]['id'], true);
        $this->assertCount(2, $replyRows);
        $clientReply = array_values(array_filter($replyRows, static fn (array $r) => $r['author_type'] === 'client'))[0];
        $this->assertSame('Ticket Owner', $clientReply['author_name']);
        $adminReply = array_values(array_filter($replyRows, static fn (array $r) => $r['author_type'] === 'admin'))[0];
        $this->assertSame('SupportAgent', $adminReply['author_name']);
    }
}
