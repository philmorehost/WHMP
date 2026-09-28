<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Import\ResellerClubImportController;
use CodeVault\Provisioning\ResellerClubEmailProvisioningModule;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ResellerClubEmailProvisioningModuleTest extends DatabaseTestCase
{
    private FakeHttpClient $http;
    private RegistrarRepository $registrars;
    private ResellerClubEmailProvisioningModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->http = new FakeHttpClient();
        $this->registrars = new RegistrarRepository($this->db);
        $this->module = new ResellerClubEmailProvisioningModule($this->http, $this->registrars);

        // Seed ResellerClub registrar configuration
        $this->registrars->setConfig('resellerclub', [
            'reseller_id' => '9999',
            'api_key' => 'RCKEY123',
            'customer_id' => '1001',
        ]);
    }

    public function test_create_sends_correct_payload_for_business_email(): void
    {
        $this->http->respondWith(200, json_encode([
            'status' => 'SUCCESS',
            'message' => 'Order placed successfully',
        ]));

        $result = $this->module->create([
            'product_name' => 'Business Email',
            'domain' => 'mycompany.com',
            'no_of_accounts' => 2,
        ]);

        $this->assertTrue($result['success']);
        $lastRequest = $this->http->lastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertStringContainsString('/mail/us/add-business.json', $lastRequest['url']);
        $this->assertStringContainsString('domain-name=mycompany.com', $lastRequest['url']);
        $this->assertStringContainsString('customer-id=1001', $lastRequest['url']);
    }

    public function test_create_sends_correct_payload_for_google_workspace(): void
    {
        $this->http->respondWith(200, json_encode([
            'status' => 'SUCCESS',
            'message' => 'Order placed successfully',
        ]));

        // product_type is the module's contract, and it is explicit: create()
        // switches on `$params['product_type']` (defaulting to business_email)
        // and NEVER infers it from the product's name. This test asserted
        // /google/add.json while passing only a product_name, so it took the
        // default branch -- the test's own name said Google Workspace, but it
        // never told the module. singleSignOn() below passes product_type too,
        // so the module is self-consistent.
        $result = $this->module->create([
            'product_name' => 'Google Workspace Business Starter',
            'product_type' => 'google_workspace',
            'domain' => 'googlecompany.com',
            'no_of_accounts' => 1,
        ]);

        $this->assertTrue($result['success']);
        $lastRequest = $this->http->lastRequest();
        $this->assertStringContainsString('/google/add.json', $lastRequest['url']);
    }

    public function test_single_sign_on_resolves_titan_and_google_sso_urls(): void
    {
        $titanSSO = $this->module->singleSignOn([
            'product_name' => 'Titan Email Hosting',
            'domain' => 'mycompany.com',
            'product_type' => 'titan_email',
        ]);
        $this->assertTrue($titanSSO['success']);
        $this->assertSame('https://titan.email/mail/?domain=mycompany.com', $titanSSO['url']);

        $googleSSO = $this->module->singleSignOn([
            'product_name' => 'Google Workspace Business Starter',
            'domain' => 'mycompany.com',
            'product_type' => 'google_workspace',
        ]);
        $this->assertTrue($googleSSO['success']);
        $this->assertSame('https://mail.google.com/a/mycompany.com', $googleSSO['url']);
    }

    public function test_import_controller_creates_products_with_markup(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $pricing = new ProductPricingRepository($this->db);
        $serverGroups = new ServerGroupRepository($this->db);
        $servers = new ServerRepository($this->db);

        // AuthGuard is FINAL, so it cannot be doubled. This test errored on the
        // createMock() before it ever reached the controller and had therefore
        // never verified the import it is named for. A real guard with a
        // super-admin session replaces the mock: AuthGuard::can() bypasses the
        // permission matrix for a super-admin, which is exactly what the mock was
        // standing in for. Names are fully qualified because this file does not
        // import them.
        $roles = new \CodeVault\Staff\RoleRepository($this->db);
        $superAdminRoleId = $roles->create('Import Super Admin', true, []);
        $adminId = (new \CodeVault\Auth\AdminRepository($this->db))->create(
            'rcimporter',
            'rcimporter@example.test',
            'secret123',
            'Import Admin',
            $superAdminRoleId
        );

        $configDir = sys_get_temp_dir() . '/codevault-rc-import-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new \CodeVault\Session\SessionManager(new \CodeVault\Config($configDir));
        $guard = new \CodeVault\Auth\AuthGuard($session, new \CodeVault\Auth\AdminRepository($this->db), $roles);
        // Seed the guard's own session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session).
        $_SESSION['admin_id'] = $adminId;

        $view = $this->createMock(\CodeVault\View::class);

        $controller = new ResellerClubImportController($guard, $view, $groups, $products, $pricing, $serverGroups, $servers);

        $request = new Request([], [
            'markup_type' => 'percentage',
            'markup_value' => '20.00', // 20% markup
        ], [], [], []);

        $controller->run($request);

        // Verify group "ResellerClub Email Hosting" exists
        $group = $groups->findByName('ResellerClub Email Hosting');
        $this->assertNotNull($group);

        // Verify product "Business Email" was created (cost 0.50 * 1.2 = 0.60)
        $product = $products->findByName('Business Email');
        $this->assertNotNull($product);
        $this->assertSame((int) $group['id'], (int) $product['product_group_id']);

        $pricingRow = $pricing->find((int) $product['id'], 'monthly');
        $this->assertNotNull($pricingRow);
        $this->assertEquals(0.60, (float) $pricingRow['price']);
    }
}
