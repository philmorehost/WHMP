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
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainSettings;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Import\WhmcsImportService;
use CodeVault\Settings\SettingsRepository;
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
        $this->remote->exec('CREATE TABLE tblservergroups (id INT PRIMARY KEY, name VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblservergroupsrel (serverid INT, groupid INT)');
        $this->remote->exec('CREATE TABLE tblproductgroups (id INT PRIMARY KEY, name VARCHAR(191), headline VARCHAR(191), `order` INT DEFAULT 0)');
        $this->remote->exec('CREATE TABLE tblproducts (id INT PRIMARY KEY, gid INT, name VARCHAR(191), description TEXT, hidden TINYINT DEFAULT 0, type VARCHAR(30))');
        $this->remote->exec('CREATE TABLE tblhosting (id INT PRIMARY KEY, userid INT, packageid INT, server INT, domain VARCHAR(191), dedicatedip VARCHAR(45), billingcycle VARCHAR(30), amount DECIMAL(10,2), domainstatus VARCHAR(30), nextduedate DATE, regdate DATETIME)');
        $this->remote->exec('CREATE TABLE tblinvoiceitems (id INT PRIMARY KEY, invoiceid INT, description VARCHAR(255), amount DECIMAL(10,2))');
        $this->remote->exec('CREATE TABLE tblconfiguration (setting VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
        $this->remote->exec('CREATE TABLE tbldomains (id INT PRIMARY KEY)');
        $this->remote->exec('CREATE TABLE tblcurrencies (id INT PRIMARY KEY, code VARCHAR(3), prefix VARCHAR(5), suffix VARCHAR(5), rate DECIMAL(10,4), `default` TINYINT DEFAULT 0)');
        $this->remote->exec('CREATE TABLE tbldomainpricing (id INT PRIMARY KEY, extension VARCHAR(20), autoreg VARCHAR(50))');
        $this->remote->exec('CREATE TABLE tbltax (id INT PRIMARY KEY, level INT, name VARCHAR(100), state VARCHAR(100), country VARCHAR(2), taxrate DECIMAL(5,2))');
        $this->remote->exec('CREATE TABLE tblcontacts (id INT PRIMARY KEY, userid INT, firstname VARCHAR(100), lastname VARCHAR(100), email VARCHAR(191), permissions VARCHAR(255))');
        $this->remote->exec('CREATE TABLE tblproductconfiggroups (id INT PRIMARY KEY, name VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblproductconfiglinks (gid INT, pid INT)');
        $this->remote->exec('CREATE TABLE tblproductconfigoptions (id INT PRIMARY KEY, gid INT, optionname VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblproductconfigoptionssub (id INT PRIMARY KEY, configid INT, optionname VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tblpricing (id INT PRIMARY KEY, type VARCHAR(20), relid INT, currency INT DEFAULT 1, monthly DECIMAL(10,2), quarterly DECIMAL(10,2), semiannually DECIMAL(10,2), annually DECIMAL(10,2), biennially DECIMAL(10,2), triennially DECIMAL(10,2), msetupfee DECIMAL(10,2), qsetupfee DECIMAL(10,2), ssetupfee DECIMAL(10,2), asetupfee DECIMAL(10,2), bsetupfee DECIMAL(10,2), tsetupfee DECIMAL(10,2))');
        $this->remote->exec('CREATE TABLE tblticketdepartments (id INT PRIMARY KEY, name VARCHAR(191), email VARCHAR(191))');
        $this->remote->exec('CREATE TABLE tbltickets (id INT PRIMARY KEY, did INT, userid INT, email VARCHAR(191), name VARCHAR(191), title VARCHAR(255), status VARCHAR(30), urgency VARCHAR(20), date DATETIME)');
        $this->remote->exec('CREATE TABLE tblticketreplies (id INT PRIMARY KEY, tid INT, message TEXT, name VARCHAR(191), admin VARCHAR(191), date DATETIME)');
        $this->remote->exec('CREATE TABLE tblpromotions (id INT PRIMARY KEY, code VARCHAR(50), type VARCHAR(20), value DECIMAL(10,2), maxuses INT, uses INT, startdate DATE, expirationdate DATE)');

        $this->importer = new WhmcsImportService($this->db, new DomainPricingRepository($this->db), new RegistrarRepository($this->db), new ProductPricingRepository($this->db), new DomainSettings(new SettingsRepository($this->db)), sys_get_temp_dir() . '/whmcs_import_test_progress.json');
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

    public function test_import_preserves_a_legacy_phpass_hash_and_randomizes_a_missing_password(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (1, 'phpass@example.test', 'Php', 'Ass', '\$P\$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (2, 'nopass@example.test', 'No', 'Password', '', 'Active', '2020-01-01 00:00:00')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);

        $clients = new ClientRepository($this->db);

        $phpass = $clients->findByEmail('phpass@example.test');
        $this->assertSame('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', $phpass['password_hash'], 'a valid phpass hash must survive the import untouched so the client can still log in');

        $nopass = $clients->findByEmail('nopass@example.test');
        $this->assertNotEmpty($nopass['password_hash'], 'a missing WHMCS password must never import as an empty hash');
        $this->assertNotSame('', $nopass['password_hash']);
        $this->assertFalse(password_verify('', $nopass['password_hash']), 'the random fallback must not be an empty-password hash');
        $this->assertFalse(password_verify('anything', $nopass['password_hash']), 'the random fallback must be unguessable, forcing the forgot-password flow');
    }

    public function test_sync_client_passwords_fills_only_empty_local_hashes(): void
    {
        // Remote WHMCS side — the accounts that hold the real passwords.
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (1, 'phpass@example.test', 'Php', 'Ass', '\$P\$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (2, 'bcrypt@example.test', 'Bc', 'Rypt', '\$2y\$10\$abcdefghijklmnopqrstuv.ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (3, 'emptyremote@example.test', 'Empty', 'Remote', '', 'Active', '2020-01-01 00:00:00')");
        // A WHMCS account with no local counterpart — must be ignored, not create anything.
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, password, status, datecreated) VALUES (4, 'ghost@example.test', 'Ghost', 'Account', '\$2y\$10\$abcdefghijklmnopqrstuv.ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi', 'Active', '2020-01-01 00:00:00')");

        // Local side — the already-migrated clients.
        $this->db->statement("INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES ('phpass@example.test', '', 'Php', 'Ass', 'active', NOW(), NOW())");
        $this->db->statement("INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES ('bcrypt@example.test', '', 'Bc', 'Rypt', 'active', NOW(), NOW())");
        $this->db->statement("INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES ('emptyremote@example.test', '', 'Empty', 'Remote', 'active', NOW(), NOW())");
        // A client who reset their password locally after migration — must be left alone.
        $this->db->statement("INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES ('working@example.test', '\$2y\$10\$localhashAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'Work', 'Ing', 'active', NOW(), NOW())");
        // A local account with no WHMCS counterpart — counted as not_found.
        $this->db->statement("INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES ('localonly@example.test', '', 'Local', 'Only', 'active', NOW(), NOW())");

        $result = $this->importer->syncClientPasswords($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['matched'], 'phpass, bcrypt and emptyremote all matched a local account');
        $this->assertSame(1, $result['not_found'], 'localonly@example.test has no WHMCS counterpart');
        $this->assertSame(1, $result['empty_remote'], 'emptyremote@example.test has no password in WHMCS');

        $clients = new ClientRepository($this->db);

        $phpass = $clients->findByEmail('phpass@example.test');
        $this->assertSame('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', $phpass['password_hash'], 'the phpass hash must be copied verbatim so the PHPass fallback can verify it');

        $bcrypt = $clients->findByEmail('bcrypt@example.test');
        $this->assertSame('$2y$10$abcdefghijklmnopqrstuv.ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi', $bcrypt['password_hash'], 'the bcrypt hash must be copied verbatim');

        $emptyRemote = $clients->findByEmail('emptyremote@example.test');
        $this->assertNotEmpty($emptyRemote['password_hash']);
        $this->assertNotSame('', $emptyRemote['password_hash']);
        $this->assertFalse(password_verify('', $emptyRemote['password_hash']), 'a WHMCS account with no password must get an unusable fallback, forcing forgot-password');

        $working = $clients->findByEmail('working@example.test');
        $this->assertSame('$2y$10$localhashAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $working['password_hash'], 'a client who already has a working hash must NOT be overwritten');

        $localOnly = $clients->findByEmail('localonly@example.test');
        $this->assertSame('', $localOnly['password_hash'], 'an account with no WHMCS match stays as-is');
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
        $this->remote->exec("INSERT INTO tbltax (id, level, country, state, name, taxrate) VALUES (1, 1, 'US', NULL, 'Sales Tax', 7.25)");
        $this->remote->exec("INSERT INTO tbltax (id, level, country, state, name, taxrate) VALUES (2, 1, 'US', 'CA', 'CA State Tax', 8.50)");
        $this->remote->exec("INSERT INTO tbltax (id, level, country, state, name, taxrate) VALUES (3, 1, NULL, NULL, 'Bad Row', 5.00)");

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
        $this->remote->exec("INSERT INTO tblticketdepartments (id, name, email) VALUES (1, 'Sales', 'sales@example.test')");
        $this->remote->exec("INSERT INTO tbltickets (id, did, userid, email, name, title, status, urgency, date) VALUES (100, 1, 1, 'ticketowner@example.test', 'Ticket Owner', 'Help with billing', 'Open', 'High', '2021-01-01 10:00:00')");
        $this->remote->exec("INSERT INTO tblticketreplies (id, tid, message, name, admin, date) VALUES (1, 100, 'Please help', 'Ticket Owner', '', '2021-01-01 10:00:00')");
        $this->remote->exec("INSERT INTO tblticketreplies (id, tid, message, name, admin, date) VALUES (2, 100, 'Sure, one moment', '', 'SupportAgent', '2021-01-01 11:00:00')");
        // A ticket for a department that never migrated — must be skipped, not error the whole step.
        $this->remote->exec("INSERT INTO tbltickets (id, did, userid, email, name, title, status, urgency, date) VALUES (101, 999, 1, 'ticketowner@example.test', 'Ticket Owner', 'Orphan dept', 'Open', 'Low', '2021-01-01 10:00:00')");

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

    public function test_import_migrates_domain_tld_pricing_and_resolves_a_matching_registrar(): void
    {
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate, `default`) VALUES (1, 'USD', '$', '', 1.0000, 1)");
        $this->remote->exec("INSERT INTO tbldomainpricing (id, extension, autoreg) VALUES (1, 'com', 'local')");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, msetupfee) VALUES (1, 'domainregister', 1, 1, 12.99)");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, msetupfee) VALUES (2, 'domaintransfer', 1, 1, 10.99)");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, msetupfee) VALUES (3, 'domainrenew', 1, 1, 14.99)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['imported']['domain_pricing']);

        $pricing = new DomainPricingRepository($this->db);
        $com = $pricing->findByTld('com');
        $this->assertNotNull($com);
        $this->assertSame('local', $com['registrar_slug']);
        $this->assertSame('12.99', $com['register_price']);
        $this->assertSame('10.99', $com['transfer_price']);
        $this->assertSame('14.99', $com['renew_price']);
    }

    public function test_import_falls_back_to_local_registrar_and_warns_when_whmcs_autoreg_module_is_unrecognized(): void
    {
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate, `default`) VALUES (1, 'USD', '$', '', 1.0000, 1)");
        $this->remote->exec("INSERT INTO tbldomainpricing (id, extension, autoreg) VALUES (1, 'net', 'enom')");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, msetupfee) VALUES (1, 'domainregister', 1, 1, 20.00)");

        $result = $this->importer->import($this->credentials());

        $this->assertFalse($result['success']); // unmatched registrar is reported as an error, not silently mismapped
        $this->assertSame(1, $result['imported']['domain_pricing']);
        $this->assertStringContainsString('enom', $result['errors'][0]['reason']);

        $pricing = new DomainPricingRepository($this->db);
        $net = $pricing->findByTld('net');
        $this->assertNotNull($net);
        $this->assertSame('local', $net['registrar_slug']);
        $this->assertSame('20.00', $net['register_price']);
        $this->assertSame('0.00', $net['transfer_price']);
    }

    public function test_import_migrates_services_with_correct_product_name_domain_and_dedicated_ip_hostname(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'hostingclient@example.test', 'Host', 'Ing', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblproductgroups (id, name) VALUES (1, 'Web Hosting')");
        $this->remote->exec("INSERT INTO tblproducts (id, gid, name, type, hidden) VALUES (1, 1, 'Shared Starter', 'hostingaccount', 0)");
        $this->remote->exec("INSERT INTO tblproducts (id, gid, name, type, hidden) VALUES (2, 1, 'Dedicated Box', 'server', 0)");
        $this->remote->exec("INSERT INTO tblhosting (id, userid, packageid, server, domain, dedicatedip, billingcycle, amount, domainstatus, nextduedate, regdate) VALUES (1, 1, 1, 0, 'shared-example.test', '', 'Monthly', 9.99, 'Active', '2026-01-01', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblhosting (id, userid, packageid, server, domain, dedicatedip, billingcycle, amount, domainstatus, nextduedate, regdate) VALUES (2, 1, 2, 0, 'placeholder.test', '203.0.113.7', 'Annually', 199.00, 'Active', '2027-01-01', '2020-06-01 00:00:00')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']['services']);

        $products = new ProductRepository($this->db);
        $dedicatedProduct = $products->findByName('Dedicated Box');
        $this->assertNotNull($dedicatedProduct);
        $this->assertSame('dedicated', $dedicatedProduct['type']);

        $services = $this->db->select('SELECT * FROM services ORDER BY id');
        $this->assertCount(2, $services);

        $this->assertSame('Shared Starter', $services[0]['product_name']);
        $this->assertSame('shared-example.test', $services[0]['domain']);
        $this->assertSame('monthly', $services[0]['billing_cycle']);
        $this->assertSame('2026-01-01', $services[0]['next_due_date']);

        $this->assertSame('Dedicated Box', $services[1]['product_name']);
        $this->assertSame('placeholder.test', $services[1]['domain']);
        $this->assertSame('203.0.113.7', $services[1]['hostname']);
        $this->assertSame('annually', $services[1]['billing_cycle']);
    }

    public function test_import_migrates_product_pricing_per_billing_cycle_in_the_default_currency(): void
    {
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate, `default`) VALUES (1, 'USD', '$', '', 1.0000, 1)");
        $this->remote->exec("INSERT INTO tblcurrencies (id, code, prefix, suffix, rate, `default`) VALUES (2, 'EUR', '', ' EUR', 0.9200, 0)");
        $this->remote->exec("INSERT INTO tblproductgroups (id, name) VALUES (1, 'Web Hosting')");
        $this->remote->exec("INSERT INTO tblproducts (id, gid, name, type, hidden) VALUES (1, 1, 'Shared Starter', 'hostingaccount', 0)");
        // A EUR row that must be ignored in favor of the USD (default) row.
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, monthly, msetupfee, annually, asetupfee) VALUES (1, 'product', 1, 2, 8.00, 0.00, 80.00, 0.00)");
        $this->remote->exec("INSERT INTO tblpricing (id, type, relid, currency, monthly, msetupfee, annually, asetupfee) VALUES (2, 'product', 1, 1, 9.99, 5.00, 99.99, 5.00)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);

        $products = new ProductRepository($this->db);
        $product = $products->findByName('Shared Starter');
        $this->assertNotNull($product);

        $pricing = new ProductPricingRepository($this->db);
        $byCycle = $pricing->forProduct((int) $product['id']);

        $this->assertSame('9.99', $byCycle['monthly']['price']);
        $this->assertSame('5.00', $byCycle['monthly']['setup_fee']);
        $this->assertSame('99.99', $byCycle['annually']['price']);
        $this->assertArrayNotHasKey('quarterly', $byCycle); // no column value supplied — no row created
    }

    public function test_import_migrates_default_nameservers_from_whmcs_general_settings(): void
    {
        $this->remote->exec("INSERT INTO tblconfiguration (setting, value) VALUES ('DomainNS1', 'ns1.example-registrar.com')");
        $this->remote->exec("INSERT INTO tblconfiguration (setting, value) VALUES ('DomainNS2', 'ns2.example-registrar.com')");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);

        $settings = new DomainSettings(new SettingsRepository($this->db));
        $this->assertSame(['ns1.example-registrar.com', 'ns2.example-registrar.com'], $settings->defaultNameservers());
    }

    public function test_import_migrates_invoice_line_items(): void
    {
        $this->remote->exec("INSERT INTO tblclients (id, email, firstname, lastname, status, datecreated) VALUES (1, 'invoiceclient@example.test', 'Inv', 'Client', 'Active', '2020-01-01 00:00:00')");
        $this->remote->exec("INSERT INTO tblinvoices (id, userid, invoicenum, subtotal, tax, tax2, total, status, date, duedate, datepaid) VALUES (10, 1, 'INV-0010', 90.00, 10.00, 0.00, 100.00, 'Paid', '2020-02-01', '2020-02-10', '2020-02-05 00:00:00')");
        $this->remote->exec("INSERT INTO tblinvoiceitems (id, invoiceid, description, amount) VALUES (1, 10, 'Shared Starter - Monthly', 9.99)");
        $this->remote->exec("INSERT INTO tblinvoiceitems (id, invoiceid, description, amount) VALUES (2, 10, 'Domain Registration - example.test', 12.99)");

        $result = $this->importer->import($this->credentials());

        $this->assertTrue($result['success']);

        $invoices = new InvoiceRepository($this->db);
        $client = (new ClientRepository($this->db))->findByEmail('invoiceclient@example.test');
        $invoice = $invoices->forClient((int) $client['id'])[0];
        $items = $invoices->items((int) $invoice['id']);

        $this->assertCount(2, $items);
        $descriptions = array_column($items, 'description');
        $this->assertContains('Shared Starter - Monthly', $descriptions);
        $this->assertContains('Domain Registration - example.test', $descriptions);
    }
}
