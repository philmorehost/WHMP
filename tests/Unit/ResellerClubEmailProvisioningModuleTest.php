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

        $result = $this->module->create([
            'product_name' => 'Google Workspace Business Starter',
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

        $guard = $this->createMock(\CodeVault\Auth\AuthGuard::class);
        $guard->method('check')->willReturn(true);
        $guard->method('can')->willReturn(true);

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
