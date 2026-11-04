<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Per-reseller gateway visibility (migration 0221): the admin can switch a
 * gateway off for clients of RESELLER stores while it stays available on the
 * platform's own site.
 *
 * "Reseller customer" is a clients row with reseller_id set — the same
 * distinction PLATFORM_ONLY draws everywhere else. The listing filter
 * (allEnabledForClient) and the payment-initiator guard
 * (PaymentCallbackController, covered by the shared isResellerClient()
 * predicate) must agree on exactly that.
 */
final class PaymentGatewayResellerVisibilityTest extends DatabaseTestCase
{
    private PaymentGatewayRepository $gateways;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->gateways = new PaymentGatewayRepository($this->db);

        // Two enabled gateways with distinct slugs (the migrator seeds the
        // manual gateway; add a card gateway beside it).
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update("UPDATE payment_gateways SET is_enabled = 1, reseller_enabled = 1, updated_at = ? WHERE slug = 'manual'", [$now]);

        $this->db->delete('DELETE FROM payment_gateways WHERE slug = ?', ['paystack']);
        $this->db->insert(
            'INSERT INTO payment_gateways (slug, name, config, is_enabled, reseller_enabled, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['paystack', 'Paystack', '{}', 1, 1, 2, $now, $now]
        );
    }

    public function test_reseller_clients_do_not_see_a_gateway_disabled_for_resellers(): void
    {
        $this->gateways->setResellerEnabled('manual', false);

        $platformClient = ['id' => 1, 'reseller_id' => null];
        $resellerClient = ['id' => 2, 'reseller_id' => 77];

        $platformSees = array_map(static fn (array $g): string => (string) $g['slug'], $this->gateways->allEnabledForClient($platformClient));
        $resellerSees = array_map(static fn (array $g): string => (string) $g['slug'], $this->gateways->allEnabledForClient($resellerClient));

        $this->assertContains('manual', $platformSees, 'the main site keeps the gateway');
        $this->assertContains('paystack', $resellerSees, 'other gateways are unaffected for reseller clients');
        $this->assertNotContains('manual', $resellerSees, 'the reseller client no longer sees the disabled gateway');
    }

    public function test_re_enabling_restores_the_gateway_for_reseller_clients(): void
    {
        $this->gateways->setResellerEnabled('paystack', false);
        $resellerClient = ['id' => 2, 'reseller_id' => 77];
        $this->assertNotContains('paystack', array_column($this->gateways->allEnabledForClient($resellerClient), 'slug'));

        $this->gateways->setResellerEnabled('paystack', true);
        $this->assertContains('paystack', array_column($this->gateways->allEnabledForClient($resellerClient), 'slug'));
    }

    public function test_globally_disabled_gateway_stays_hidden_even_from_the_main_site(): void
    {
        // The reseller flag only narrows further; it can never widen a
        // globally disabled gateway back into anyone's list.
        $this->gateways->setEnabled('manual', false);
        $this->gateways->setResellerEnabled('manual', true);

        $platformClient = ['id' => 1, 'reseller_id' => null];
        $this->assertNotContains('manual', array_column($this->gateways->allEnabledForClient($platformClient), 'slug'));
    }

    public function test_is_reseller_client_matches_the_reseller_id_rule(): void
    {
        $this->assertTrue(PaymentGatewayRepository::isResellerClient(['id' => 5, 'reseller_id' => 77]));
        $this->assertFalse(PaymentGatewayRepository::isResellerClient(['id' => 5, 'reseller_id' => null]));
        $this->assertFalse(PaymentGatewayRepository::isResellerClient(['id' => 5]), 'a row without the column counts as platform');
        $this->assertFalse(PaymentGatewayRepository::isResellerClient(null), 'no client at all is not a reseller client');
    }
}
