<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Database\Migrator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class TaxCalculatorTest extends DatabaseTestCase
{
    private TaxRuleRepository $rules;
    private TaxSettings $taxSettings;
    private TaxCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->rules = new TaxRuleRepository($this->db);
        $this->taxSettings = new TaxSettings(new SettingsRepository($this->db));
        $this->calculator = new TaxCalculator($this->rules, new VatNumberValidator(), $this->taxSettings);
    }

    public function test_no_rule_means_no_tax(): void
    {
        $result = $this->calculator->calculate(['country' => 'US', 'state' => null, 'tax_exempt' => false], 100.00);

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_whole_country_rule_applies(): void
    {
        $this->rules->setRate('NG', null, 'VAT', 7.5);

        $result = $this->calculator->calculate(['country' => 'NG', 'state' => null, 'tax_exempt' => false], 100.00);

        $this->assertSame(7.5, $result['rate']);
        $this->assertSame(7.5, $result['amount']);
    }

    public function test_state_specific_rule_overrides_country_wide_rule(): void
    {
        $this->rules->setRate('US', null, 'Sales Tax', 0.0);
        $this->rules->setRate('US', 'CA', 'CA Sales Tax', 7.25);

        $caResult = $this->calculator->calculate(['country' => 'US', 'state' => 'CA', 'tax_exempt' => false], 100.00);
        $txResult = $this->calculator->calculate(['country' => 'US', 'state' => 'TX', 'tax_exempt' => false], 100.00);

        $this->assertSame(7.25, $caResult['rate']);
        $this->assertSame(0.0, $txResult['rate']);
    }

    public function test_tax_exempt_client_is_never_taxed_even_with_a_matching_rule(): void
    {
        $this->rules->setRate('NG', null, 'VAT', 7.5);

        $result = $this->calculator->calculate(['country' => 'NG', 'state' => null, 'tax_exempt' => true], 100.00);

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_set_rate_twice_updates_rather_than_duplicates(): void
    {
        $this->rules->setRate('NG', null, 'VAT', 5.0);
        $this->rules->setRate('NG', null, 'VAT', 7.5);

        $this->assertCount(1, $this->rules->all());
        $this->assertSame(7.5, (float) $this->rules->resolve('NG', null)['rate']);
    }

    public function test_amount_is_rounded_to_two_decimal_places(): void
    {
        $this->rules->setRate('NG', null, 'VAT', 7.5);

        $result = $this->calculator->calculate(['country' => 'NG', 'state' => null, 'tax_exempt' => false], 33.33);

        $this->assertSame(round(33.33 * 0.075, 2), $result['amount']);
    }

    // --- R22: reverse charge -------------------------------------------------

    public function test_no_reverse_charge_result_key_is_false_on_ordinary_calculations(): void
    {
        $result = $this->calculator->calculate(['country' => 'US', 'state' => null, 'tax_exempt' => false], 100.00);

        $this->assertFalse($result['reverseCharge']);
    }

    public function test_cross_border_client_with_valid_vat_number_gets_zero_rated_reverse_charge(): void
    {
        $this->taxSettings->setSellerCountryCode('DE');
        $this->rules->setRate('FR', null, 'VAT', 20.0);

        $result = $this->calculator->calculate([
            'country' => 'FR',
            'state' => null,
            'tax_exempt' => false,
            'vat_number' => 'FR12345678901',
        ], 100.00);

        $this->assertTrue($result['reverseCharge']);
        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.0, $result['amount']);
        $this->assertStringContainsString('Reverse Charge', $result['name']);
    }

    public function test_same_country_client_never_gets_reverse_charge_even_with_a_valid_vat_number(): void
    {
        $this->taxSettings->setSellerCountryCode('FR');
        $this->rules->setRate('FR', null, 'VAT', 20.0);

        $result = $this->calculator->calculate([
            'country' => 'FR',
            'state' => null,
            'tax_exempt' => false,
            'vat_number' => 'FR12345678901',
        ], 100.00);

        $this->assertFalse($result['reverseCharge']);
        $this->assertSame(20.0, $result['rate']);
    }

    public function test_malformed_vat_number_does_not_trigger_reverse_charge(): void
    {
        $this->taxSettings->setSellerCountryCode('DE');
        $this->rules->setRate('FR', null, 'VAT', 20.0);

        $result = $this->calculator->calculate([
            'country' => 'FR',
            'state' => null,
            'tax_exempt' => false,
            'vat_number' => 'not-a-vat-number',
        ], 100.00);

        $this->assertFalse($result['reverseCharge']);
        $this->assertSame(20.0, $result['rate']);
    }

    public function test_no_seller_country_configured_means_reverse_charge_never_applies(): void
    {
        $this->rules->setRate('FR', null, 'VAT', 20.0);

        $result = $this->calculator->calculate([
            'country' => 'FR',
            'state' => null,
            'tax_exempt' => false,
            'vat_number' => 'FR12345678901',
        ], 100.00);

        $this->assertFalse($result['reverseCharge']);
        $this->assertSame(20.0, $result['rate']);
    }

    public function test_blank_vat_number_does_not_trigger_reverse_charge(): void
    {
        $this->taxSettings->setSellerCountryCode('DE');
        $this->rules->setRate('FR', null, 'VAT', 20.0);

        $result = $this->calculator->calculate([
            'country' => 'FR',
            'state' => null,
            'tax_exempt' => false,
            'vat_number' => '',
        ], 100.00);

        $this->assertFalse($result['reverseCharge']);
        $this->assertSame(20.0, $result['rate']);
    }
}
