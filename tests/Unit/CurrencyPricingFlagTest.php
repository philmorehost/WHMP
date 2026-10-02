<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The pricing-currency flag: `currencies.is_pricing`.
 *
 * This is the one setting that says "the figures in the product, add-on and
 * domain pricing fields were typed in THIS currency", and it is independent of
 * `is_default` (the unit every rate is quoted against). Until 0182 the catalog
 * was assumed to be priced in the base currency, which quoted a ₦22,350 plan
 * as $22,350 to a dollar client — the raw figure multiplied by nothing.
 *
 * It had NO test coverage at all: every `setPricing(` in the suite is
 * `ProductPricingRepository::setPricing()` (a product's price for a cycle), a
 * different method on a different class that merely shares the name. So the
 * whole conversion rested on a flag nothing exercised — and `pricing()`
 * silently falls back to the default when nothing is marked, which is exactly
 * the state an install is in before an admin clicks 💲 Prices.
 *
 * The scenario throughout is the live one: USD is the base and default at 1.0,
 * the catalogue was typed in naira, and naira runs at 1490 per USD.
 */
final class CurrencyPricingFlagTest extends DatabaseTestCase
{
    /** The figure actually typed into the pricing field. */
    private const TYPED_NAIRA_PRICE = 22350.0;

    /** Naira per 1 USD — the rate the typed figure was set against. */
    private const NAIRA_PER_USD = 1490.0;

    private CurrencyRepository $currencies;
    private CurrencyService $service;
    private int $ngnId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->currencies = new CurrencyRepository($this->db);
        $this->service = new CurrencyService($this->currencies);

        $this->ngnId = $this->currencies->create('NGN', '₦', self::NAIRA_PER_USD);
    }

    public function test_an_unmarked_catalog_is_treated_as_the_base_currency(): void
    {
        // The state every install is in before the flag is set, and the reason
        // a relabel is not safe on its own — see the pair test below.
        $this->assertSame('USD', $this->currencies->pricing()['code']);
    }

    public function test_only_one_currency_can_hold_the_pricing_flag(): void
    {
        $eurId = $this->currencies->create('EUR', '€', 0.9200);

        $this->currencies->setPricing($this->ngnId);
        $this->currencies->setPricing($eurId);

        $marked = $this->db->select('SELECT code FROM currencies WHERE is_pricing = 1');

        $this->assertCount(1, $marked, 'exactly one row may be marked, or "which currency is the catalog in" has no answer');
        $this->assertSame('EUR', $marked[0]['code']);

        // Marking the pricing currency must not move the base: they are
        // different roles, and conflating them is what mis-quoted the plan.
        $this->assertSame('USD', $this->currencies->default()['code']);
    }

    public function test_a_dollar_client_pays_the_naira_price_converted(): void
    {
        $this->currencies->setPricing($this->ngnId);

        $usd = $this->currencies->default();
        $rate = $this->service->catalogRate($usd);

        // 22350 / 1490 = 15.00 exactly.
        $this->assertSame(15.0, $this->service->convert(self::TYPED_NAIRA_PRICE, $rate));
        $this->assertSame('$15.00', $this->service->format(self::TYPED_NAIRA_PRICE, $usd));
    }

    public function test_a_naira_client_pays_the_typed_figure_unchanged(): void
    {
        $this->currencies->setPricing($this->ngnId);

        $ngn = $this->currencies->find($this->ngnId);

        // The pricing currency converts to itself at 1.0: a naira client must
        // see the figure that was typed, not that figure times 1490.
        $this->assertSame(1.0, $this->service->catalogRate($ngn));
        $this->assertSame('₦22,350.00', $this->service->format(self::TYPED_NAIRA_PRICE, $ngn));
    }

    /**
     * The property the relabel procedure depends on, asserted as a PAIR: the
     * same catalogue figure and the same naira client, twice.
     *
     * Relabelling a client's rows to naira fixes what those rows SAY, but it
     * does nothing about what the catalogue is priced in. With the flag unset,
     * every future order for that client takes the typed 22350 and multiplies
     * it by the naira rate, because the catalogue is assumed to be base. Only
     * the marked run is the figure the admin typed.
     *
     * A single-configuration test cannot show this: either run on its own looks
     * like a plausible number.
     */
    public function test_marking_the_flag_is_what_makes_a_naira_clients_price_correct(): void
    {
        $ngn = $this->currencies->find($this->ngnId);

        $unmarked = $this->service->convert(self::TYPED_NAIRA_PRICE, $this->service->catalogRate($ngn));

        $this->currencies->setPricing($this->ngnId);

        $marked = $this->service->convert(self::TYPED_NAIRA_PRICE, $this->service->catalogRate($ngn));

        $this->assertSame(
            self::TYPED_NAIRA_PRICE * self::NAIRA_PER_USD,
            $unmarked,
            'unmarked, a naira-typed catalogue multiplies a naira client by the rate'
        );
        $this->assertSame(self::TYPED_NAIRA_PRICE, $marked, 'marked, the typed figure stands');
        $this->assertNotSame($unmarked, $marked);
    }

    public function test_a_visitors_own_currency_still_wins_over_a_naira_catalog(): void
    {
        // The reseller question: does pricing the catalogue in naira stop a
        // customer paying in dollars? It does not. The catalogue is only the
        // base a figure converts FROM — which currency a visitor is charged in
        // is resolved from their own choice, never from the pricing flag.
        $this->currencies->setPricing($this->ngnId);

        $usd = $this->currencies->default();
        $usdId = (int) $usd['id'];

        $chosen = $this->service->resolveEffective(['currency_id' => $this->ngnId], $usdId);

        $this->assertSame('USD', $chosen['code'], 'a session choice of USD must survive a naira catalogue');
        $this->assertSame('$15.00', $this->service->format(self::TYPED_NAIRA_PRICE, $chosen));
    }
}
