<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\TaxSettings;
use CodeVault\Database\Migrator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class TaxSettingsTest extends DatabaseTestCase
{
    private TaxSettings $taxSettings;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->taxSettings = new TaxSettings(new SettingsRepository($this->db));
    }

    public function test_defaults_to_null_when_nothing_saved_yet(): void
    {
        $this->assertNull($this->taxSettings->sellerCountryCode());
    }

    public function test_set_and_get_round_trips_and_uppercases(): void
    {
        $this->taxSettings->setSellerCountryCode('de');

        $this->assertSame('DE', $this->taxSettings->sellerCountryCode());
    }

    public function test_setting_null_clears_it_back_to_unset(): void
    {
        $this->taxSettings->setSellerCountryCode('DE');
        $this->taxSettings->setSellerCountryCode(null);

        $this->assertNull($this->taxSettings->sellerCountryCode());
    }
}
