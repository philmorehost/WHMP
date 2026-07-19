<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class CurrencyServiceTest extends DatabaseTestCase
{
    private CurrencyRepository $currencies;
    private CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->currencies = new CurrencyRepository($this->db);
        $this->service = new CurrencyService($this->currencies);
    }

    public function test_default_currency_is_the_seeded_usd_row(): void
    {
        $default = $this->currencies->default();

        $this->assertSame('USD', $default['code']);
        $this->assertSame(1, (int) $default['is_default']);
    }

    public function test_resolve_for_client_falls_back_to_default_when_client_has_no_preference(): void
    {
        $currency = $this->service->resolveForClient(['currency_id' => null]);

        $this->assertSame('USD', $currency['code']);
    }

    public function test_resolve_for_client_uses_saved_preference(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.9200);

        $currency = $this->service->resolveForClient(['currency_id' => $eurId]);

        $this->assertSame('EUR', $currency['code']);
    }

    public function test_resolve_for_client_with_null_client_uses_default(): void
    {
        $currency = $this->service->resolveForClient(null);

        $this->assertSame('USD', $currency['code']);
    }

    public function test_convert_applies_rate_and_rounds_to_two_decimals(): void
    {
        $this->assertSame(92.33, $this->service->convert(100.36, 0.92));
    }

    public function test_format_prefixes_symbol_and_formats_thousands(): void
    {
        $currency = ['symbol' => '€', 'exchange_rate' => 0.9];

        $this->assertSame('€900.00', $this->service->format(1000.00, $currency));
    }

    public function test_locked_columns_for_default_currency_client_store_null_id(): void
    {
        $locked = $this->service->lockedColumnsFor(['currency_id' => null]);

        $this->assertNull($locked['currency_id']);
        $this->assertSame(1.0, $locked['currency_rate']);
    }

    public function test_locked_columns_for_non_default_currency_lock_the_rate_at_call_time(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.9200);

        $locked = $this->service->lockedColumnsFor(['currency_id' => $eurId]);

        $this->assertSame($eurId, $locked['currency_id']);
        $this->assertSame(0.9200, $locked['currency_rate']);

        // Changing the live rate afterwards must not affect the already-locked value.
        $this->currencies->update($eurId, 'EUR', '€', 0.8000);
        $relocked = $this->service->lockedColumnsFor(['currency_id' => $eurId]);
        $this->assertSame(0.8000, $relocked['currency_rate']);
        $this->assertSame(0.9200, $locked['currency_rate'], 'previously-locked array must stay unchanged');
    }

    public function test_resolve_effective_prefers_session_currency_over_client_preference(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.92);
        $gbpId = $this->currencies->create('GBP', '£', 0.79);

        $currency = $this->service->resolveEffective(['currency_id' => $eurId], $gbpId);

        $this->assertSame('GBP', $currency['code']);
    }

    public function test_resolve_effective_falls_back_to_client_when_no_session_currency(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.92);

        $currency = $this->service->resolveEffective(['currency_id' => $eurId], null);

        $this->assertSame('EUR', $currency['code']);
    }

    public function test_resolve_locked_with_null_currency_id_returns_default(): void
    {
        $this->assertSame('USD', $this->service->resolveLocked(null)['code']);
    }

    public function test_format_locked_uses_the_supplied_rate_not_the_currencys_live_rate(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.92);
        $this->currencies->update($eurId, 'EUR', '€', 0.50);

        // A historical invoice locked at 0.92 must still render at 0.92
        // even though the currency's live rate has since moved to 0.50.
        $this->assertSame('€92.00', $this->service->formatLocked(100.00, $eurId, 0.92));
    }

    public function test_set_default_moves_default_flag_atomically(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.92);
        $this->currencies->setDefault($eurId);

        $default = $this->currencies->default();
        $this->assertSame('EUR', $default['code']);

        $usd = $this->currencies->findByCode('USD');
        $this->assertSame(0, (int) $usd['is_default']);
    }

    public function test_delete_refuses_to_remove_the_default_currency(): void
    {
        $usd = $this->currencies->findByCode('USD');

        $deleted = $this->currencies->delete((int) $usd['id']);

        $this->assertFalse($deleted);
        $this->assertNotNull($this->currencies->findByCode('USD'));
    }

    public function test_delete_removes_a_non_default_currency(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.92);

        $deleted = $this->currencies->delete($eurId);

        $this->assertTrue($deleted);
        $this->assertNull($this->currencies->find($eurId));
    }
}
