<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Reseller\ClientSiteAccess;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Which site a client account belongs to — the rule behind "this email is registered
 * with another provider" at sign-up, and behind refusing another provider's customer
 * at sign-in.
 */
final class ClientSiteAccessTest extends TestCase
{
    private const CLIENT = 42;

    public function test_an_account_belongs_on_its_own_site(): void
    {
        $this->assertSame(ClientSiteAccess::SAME_SITE, ClientSiteAccess::decide(self::CLIENT, 7, 7, 500, true));
        $this->assertSame(ClientSiteAccess::SAME_SITE, ClientSiteAccess::decide(self::CLIENT, null, null, null, true));
    }

    public function test_a_stores_customer_is_another_providers_on_a_different_store(): void
    {
        $this->assertSame(ClientSiteAccess::OTHER_PROVIDER, ClientSiteAccess::decide(self::CLIENT, 7, 8, 501, true));
    }

    public function test_a_stores_customer_is_another_providers_on_the_platform_site(): void
    {
        $this->assertSame(ClientSiteAccess::OTHER_PROVIDER, ClientSiteAccess::decide(self::CLIENT, 7, null, null, true));
    }

    public function test_a_platform_customer_with_history_is_another_providers_on_a_store(): void
    {
        $this->assertSame(ClientSiteAccess::OTHER_PROVIDER, ClientSiteAccess::decide(self::CLIENT, null, 7, 500, true));
    }

    public function test_an_account_that_never_bought_anything_may_be_used_on_any_store(): void
    {
        // Nothing of theirs belongs to anyone yet; the store they order from claims them.
        $this->assertSame(ClientSiteAccess::SAME_SITE, ClientSiteAccess::decide(self::CLIENT, null, 7, 500, false));
    }

    public function test_a_store_owner_may_use_their_own_store_but_not_someone_elses(): void
    {
        $this->assertSame(ClientSiteAccess::SAME_SITE, ClientSiteAccess::decide(500, null, 7, 500, true));
        $this->assertSame(ClientSiteAccess::OTHER_PROVIDER, ClientSiteAccess::decide(500, null, 8, 501, true));
    }

    public function test_the_live_check_reads_the_site_being_served(): void
    {
        $db = (new ScriptedDatabase())
            ->on('/FROM invoices WHERE client_id/', static fn (array $b): array => (int) $b[0] === 43 ? [['id' => 9]] : []);
        $current = new CurrentReseller();
        $access = new ClientSiteAccess($db, $current);

        $storeCustomer = ['id' => 42, 'reseller_id' => 7];
        $platformCustomer = ['id' => 43, 'reseller_id' => null];
        $prospect = ['id' => 44, 'reseller_id' => null];

        // On the platform's own site.
        $this->assertFalse($access->canSignInHere($storeCustomer));
        $this->assertTrue($access->canSignInHere($platformCustomer));

        // On store 7.
        $current->set(['id' => 7, 'client_id' => 500, 'slug' => 'acme']);
        $this->assertTrue($access->canSignInHere($storeCustomer));
        $this->assertFalse($access->canSignInHere($platformCustomer), 'an invoice makes them the platform\'s');
        $this->assertTrue($access->canSignInHere($prospect));
        $this->assertTrue($access->isUnclaimed($prospect));
        $this->assertFalse($access->isUnclaimed($platformCustomer));
    }

    public function test_the_refusal_never_names_a_provider(): void
    {
        $message = ClientSiteAccess::otherProviderMessage();

        $this->assertStringContainsString('another provider on this platform', $message);
        $this->assertStringContainsString('account move', $message);
    }
}
