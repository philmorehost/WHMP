<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Reseller\ResellerMailIdentity;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The complete sender for a store's mail, and the store address its links point at.
 * Neither may ever be the platform's.
 */
final class ResellerMailSenderTest extends TestCase
{
    private ?string $savedAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://philmorehost.test';
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }

        parent::tearDown();
    }

    private function locator(): ResellerStoreLocator
    {
        return new ResellerStoreLocator(
            new ResellerStoreRepository(new ScriptedDatabase()),
            new Config(sys_get_temp_dir() . '/codevault-sender-noenv-' . uniqid())
        );
    }

    public function test_the_stores_own_support_address_is_used_when_it_has_one(): void
    {
        $sender = ResellerMailIdentity::senderFor(['brand_name' => 'Acme', 'support_email' => 'help@acme.test'], 'acme.test');

        $this->assertSame(['name' => 'Acme', 'email' => 'help@acme.test'], $sender);
    }

    public function test_without_one_it_is_a_no_reply_address_on_the_stores_host_never_ours(): void
    {
        $sender = ResellerMailIdentity::senderFor(['brand_name' => 'Acme', 'support_email' => 'not an address'], 'acme.test');

        $this->assertSame('noreply@acme.test', $sender['email']);
    }

    public function test_a_store_with_no_brand_name_is_called_by_its_slug(): void
    {
        $this->assertSame('Acme Hosting', ResellerMailIdentity::displayName(['brand_name' => '  ', 'slug' => 'acme-hosting']));
    }

    public function test_a_verified_custom_domain_is_the_stores_address(): void
    {
        $store = ['slug' => 'acme', 'custom_domain' => 'Acme.Test', 'domain_verified_at' => '2026-01-01 00:00:00'];

        $this->assertSame('acme.test', $this->locator()->hostFor($store));
        $this->assertSame('https://acme.test', $this->locator()->baseUrlFor($store));
    }

    public function test_an_unverified_domain_is_never_linked_to(): void
    {
        $store = ['slug' => 'acme', 'custom_domain' => 'acme.test', 'domain_verified_at' => null];

        $this->assertSame('https://acme.philmorehost.test', $this->locator()->baseUrlFor($store));
    }
}
