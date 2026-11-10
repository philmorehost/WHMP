<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CryptoManualGateway;
use CodeVault\Billing\CryptoManualProofService;
use PHPUnit\Framework\TestCase;

/**
 * The manual crypto gateway's rules: who may see it (audience toggles for
 * platform clients and resellers, independently), what counts as a valid
 * TRC-20 wallet, and the fact that nothing in it ever captures money by
 * itself — approval is a human action on the review queue.
 */
final class ManualCryptoGatewayTest extends TestCase
{
    public function test_audience_defaults_to_available_for_clients_and_resellers(): void
    {
        $this->assertTrue(CryptoManualGateway::audienceAllows([], false, false));
        $this->assertTrue(CryptoManualGateway::audienceAllows([], false, true));
    }

    public function test_clients_toggle_hides_it_from_platform_clients_only(): void
    {
        $config = ['enable_for_clients' => '0', 'enable_for_resellers' => '1'];

        $this->assertFalse(CryptoManualGateway::audienceAllows($config, false, false), 'platform client');
        $this->assertTrue(CryptoManualGateway::audienceAllows($config, false, true), 'reseller still allowed');
    }

    public function test_resellers_toggle_hides_it_from_resellers_only(): void
    {
        $config = ['enable_for_clients' => '1', 'enable_for_resellers' => ''];

        $this->assertTrue(CryptoManualGateway::audienceAllows($config, false, false), 'platform client still allowed');
        $this->assertFalse(CryptoManualGateway::audienceAllows($config, false, true), 'reseller hidden');
    }

    public function test_storefront_customers_pass_through_the_audience_gate(): void
    {
        // Their visibility is governed by the row's reseller_enabled column
        // (checked before this call) — the audience toggles are about who
        // pays the PLATFORM, and must never leak into storefront checkout.
        $config = ['enable_for_clients' => '0', 'enable_for_resellers' => '0'];

        $this->assertTrue(CryptoManualGateway::audienceAllows($config, true, false));
    }

    public function test_trc20_wallet_validation(): void
    {
        $this->assertTrue(CryptoManualGateway::walletAddressIsValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'), 'the real USDT TRC-20 contract address');
        $this->assertTrue(CryptoManualGateway::walletAddressIsValid('TXYZsyYcWTFTxBmsNYsmYqpcKd3YbQuLA9'));

        $this->assertFalse(CryptoManualGateway::walletAddressIsValid(''), 'empty');
        $this->assertFalse(CryptoManualGateway::walletAddressIsValid('0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984'), 'an EVM address, not TRON');
        $this->assertFalse(CryptoManualGateway::walletAddressIsValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgj6t'), 'too short');
        $this->assertFalse(CryptoManualGateway::walletAddressIsValid('AR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'), 'must start with T');
        $this->assertFalse(CryptoManualGateway::walletAddressIsValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj0t'), '0 is not in the base58 alphabet');
    }

    public function test_whitespace_only_wallet_counts_as_unset(): void
    {
        $this->assertSame('', CryptoManualGateway::walletAddress(['wallet_address' => '   ']));
        $this->assertSame('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', CryptoManualGateway::walletAddress(['wallet_address' => ' TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t ']));
    }

    public function test_the_gateway_never_captures_money_by_itself(): void
    {
        $gateway = new CryptoManualGateway();

        $this->assertFalse($gateway->isOffsite());
        $this->assertFalse($gateway->capture([])['success']);
        $this->assertFalse($gateway->refund([])['success']);
        $this->assertFalse($gateway->handleCallback([], [])['valid']);
    }

    public function test_config_options_expose_the_wallet_and_both_audience_toggles(): void
    {
        $options = (new CryptoManualGateway())->configOptions();

        $this->assertArrayHasKey('wallet_address', $options);
        $this->assertSame('checkbox', $options['enable_for_clients']['type']);
        $this->assertSame('checkbox', $options['enable_for_resellers']['type']);
    }
}
