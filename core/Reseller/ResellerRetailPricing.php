<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * What a reseller's customers pay, and what the reseller keeps.
 *
 * Three figures per item, and keeping them straight is the whole job:
 *
 *   list   — our catalogue price (`ResellerPricing`, catalog currency)
 *   cost   — what the reseller owes us (list less the admin's discount)
 *   retail — what the customer pays (this class)
 *
 * The reseller's margin is retail − cost. Our revenue is cost, which is why a
 * reseller who prices below cost is a problem for THEM, not for us: we still
 * collect our cost, and they owe the difference. So a below-cost price is
 * refused when it is set (it is nearly always a typo, and it creates a debt
 * nobody agreed to) but never silently rewritten afterwards — if an admin later
 * changes the reseller discount such that a previously-fine price falls under
 * cost, `belowCost()` reports it and the UI warns rather than quietly changing
 * the price the reseller set.
 *
 * Retail is computed over the CATALOGUE list price, in the same currency
 * `ResellerPricing` works in, and for the same reason: applying a percentage to
 * an already-converted figure is how a catalogue price becomes a different
 * number than the admin typed (see the money notes in ResellerPricing).
 *
 * SUB-RESELLERS (a store whose owner is a customer of another store, its UPLINE)
 *
 * A sub-reseller buys at the upline's RETAIL prices, not at our reseller discount,
 * so the upline keeps its profit on a customer who turned reseller:
 *
 *   base   — the upline's retail for the item (what the upline's customers pay)
 *   cost   — = base: what the sub-reseller owes
 *   retail — the sub-reseller's markup/override applied over base
 *   upline_cost — what the UPLINE owes us (list less our discount); the upline
 *            earns cost − upline_cost on the sale (ResellerLedgerService)
 *
 * The chain is followed at most MAX_CHAIN levels, which also stops a cycle (two
 * stores whose owners are each other's customers) from recursing forever. New
 * chains cannot go past two tiers (ResellerEligibility).
 */
final class ResellerRetailPricing
{
    /** A reseller pricing 10x list is almost certainly a mistake; 0% is the floor. */
    public const MAX_MARKUP = 1000.0;

    /** How many uplines a price may be built through. */
    private const MAX_CHAIN = 3;

    /** @var array<int, array<string, mixed>|null> upline store per store id, for this request */
    private array $uplineCache = [];

    /** @var array<int, array<string, float>> product overrides, cached per store for this request */
    private array $productCache = [];

    /** @var array<int, array<string, array{register: ?float, transfer: ?float, renew: ?float}>> */
    private array $domainCache = [];

    public function __construct(
        private readonly ResellerPricing $cost,
        private readonly ResellerRetailPriceRepository $overrides,
        // Trailing and optional: without it every store prices as a first-tier store,
        // which is what the hand-built instances in the tests expect.
        private readonly ?ResellerStoreRepository $stores = null
    ) {
    }

    /**
     * The store's upline (the store its owner registered under), or null for a
     * store opened by one of the platform's own customers.
     *
     * @param array<string, mixed> $store
     * @return array<string, mixed>|null
     */
    public function uplineOf(array $store): ?array
    {
        $id = (int) ($store['id'] ?? 0);

        if ($this->stores === null || $id <= 0) {
            return null;
        }

        if (!array_key_exists($id, $this->uplineCache)) {
            $this->uplineCache[$id] = $this->stores->uplineFor($store);
        }

        return $this->uplineCache[$id];
    }

    /**
     * The figure the store's markup applies to, for an item with no per-item
     * override (setup fees, configurable options): list on a first-tier store, the
     * upline's markup price on a sub-reseller's.
     *
     * @param array<string, mixed> $store
     */
    public function basePriceFor(float $listPrice, array $store, int $depth = 0): float
    {
        $upline = $depth < self::MAX_CHAIN ? $this->uplineOf($store) : null;

        return $upline === null ? $listPrice : $this->retailPriceFor($listPrice, $upline, $depth + 1);
    }

    /**
     * What the store's customer pays for an item with no per-item override.
     *
     * @param array<string, mixed> $store
     */
    public function retailPriceFor(float $listPrice, array $store, int $depth = 0): float
    {
        return $this->priceFor($this->basePriceFor($listPrice, $store, $depth), $this->markupFor($store), null);
    }

    /**
     * What the store owes for an item with no per-item override: our discounted
     * price on a first-tier store, the upline's price on a sub-reseller's.
     *
     * @param array<string, mixed> $store
     */
    public function storeCostFor(float $listPrice, string $kind, array $store, int $depth = 0): float
    {
        $upline = $depth < self::MAX_CHAIN ? $this->uplineOf($store) : null;

        return $upline === null
            ? $this->cost->resellerPrice($listPrice, $kind)
            : $this->retailPriceFor($listPrice, $upline, $depth + 1);
    }

    /**
     * What the store's UPLINE owes for the same item, or null when there is none.
     *
     * @param array<string, mixed> $store
     */
    public function uplineCostFor(float $listPrice, string $kind, array $store): ?float
    {
        $upline = $this->uplineOf($store);

        return $upline === null ? null : $this->storeCostFor($listPrice, $kind, $upline, 1);
    }

    /** 0–1000, to two decimals. Negative would sell below list; the ceiling is a typo guard. */
    public static function clampMarkup(float $percent): float
    {
        if ($percent < 0.0) {
            return 0.0;
        }

        return $percent > self::MAX_MARKUP ? self::MAX_MARKUP : round($percent, 2);
    }

    /** @param array<string, mixed> $store */
    public function markupFor(array $store): float
    {
        return self::clampMarkup((float) ($store['markup_percent'] ?? 0));
    }

    /**
     * One price, from the three inputs that decide it: the catalogue figure,
     * the store's markup, and an optional hand-set override.
     *
     * An override wins outright — that is what makes it an override — and only
     * the markup (not the override) is clamped, because the reseller's own
     * figure is theirs to choose.
     */
    public function priceFor(float $listPrice, float $markupPercent, ?float $override): float
    {
        if ($override !== null) {
            return round($override, 2);
        }

        return round($listPrice * (1 + self::clampMarkup($markupPercent) / 100), 2);
    }

    /**
     * The full three-way quote for one product cycle: what the catalogue says,
     * what the reseller owes, what the customer pays, and the difference.
     *
     * On a sub-reseller's store `list` is the BASE the store prices from (the
     * upline's retail) and `catalogue_list` is our own list price.
     *
     * @param array<string, mixed> $store
     * @return array{list: float, catalogue_list: float, cost: float, retail: float, margin: float, discount_percent: float, markup_percent: float, overridden: bool, upline_id: ?int, upline_cost: ?float}
     */
    public function quoteProduct(float $listPrice, array $store, int $productId, string $cycle, int $depth = 0): array
    {
        $override = $this->productOverrides($store)[$productId . ':' . $cycle] ?? null;
        $markup = $this->markupFor($store);
        $upline = $depth < self::MAX_CHAIN ? $this->uplineOf($store) : null;

        if ($upline !== null) {
            $up = $this->quoteProduct($listPrice, $upline, $productId, $cycle, $depth + 1);

            return $this->uplineQuote($listPrice, $up, $markup, $override, (int) $upline['id']);
        }

        $cost = $this->cost->resellerPrice($listPrice, 'service');
        $retail = $this->priceFor($listPrice, $markup, $override);

        return [
            'list' => round($listPrice, 2),
            'catalogue_list' => round($listPrice, 2),
            'cost' => $cost,
            'retail' => $retail,
            'margin' => round($retail - $cost, 2),
            'discount_percent' => $this->cost->discountFor('service'),
            'markup_percent' => $markup,
            'overridden' => $override !== null,
            'upline_id' => null,
            'upline_cost' => null,
        ];
    }

    /**
     * A sub-reseller's quote, built over its upline's.
     *
     * @param array<string, mixed> $up the upline's quote for the same item
     * @return array<string, mixed>
     */
    private function uplineQuote(float $listPrice, array $up, float $markup, ?float $override, int $uplineId): array
    {
        $base = (float) $up['retail'];
        $retail = $this->priceFor($base, $markup, $override);

        return [
            'list' => round($base, 2),
            'catalogue_list' => round($listPrice, 2),
            'cost' => round($base, 2),
            'retail' => $retail,
            'margin' => round($retail - $base, 2),
            'discount_percent' => 0.0,
            'markup_percent' => $markup,
            'overridden' => $override !== null,
            'upline_id' => $uplineId,
            'upline_cost' => (float) $up['cost'],
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @param 'register'|'transfer'|'renew' $which
     * @return array{list: float, catalogue_list: float, cost: float, retail: float, margin: float, discount_percent: float, markup_percent: float, overridden: bool, upline_id: ?int, upline_cost: ?float}
     */
    public function quoteDomain(float $listPrice, array $store, string $tld, string $which, int $depth = 0): array
    {
        $override = $this->domainOverrides($store)[$tld][$which] ?? null;
        $markup = $this->markupFor($store);
        $upline = $depth < self::MAX_CHAIN ? $this->uplineOf($store) : null;

        if ($upline !== null) {
            $up = $this->quoteDomain($listPrice, $upline, $tld, $which, $depth + 1);

            return $this->uplineQuote($listPrice, $up, $markup, $override, (int) $upline['id']);
        }

        $cost = $this->cost->resellerPrice($listPrice, 'domain');
        $retail = $this->priceFor($listPrice, $markup, $override);

        return [
            'list' => round($listPrice, 2),
            'catalogue_list' => round($listPrice, 2),
            'cost' => $cost,
            'retail' => $retail,
            'margin' => round($retail - $cost, 2),
            'discount_percent' => $this->cost->discountFor('domain'),
            'markup_percent' => $markup,
            'overridden' => $override !== null,
            'upline_id' => null,
            'upline_cost' => null,
        ];
    }

    /**
     * The price list a SUB-RESELLER buys from — their upline's retail prices — in
     * the shape ResellerPricing::serviceCatalogue() returns, so the reseller area
     * shows it exactly where a first-tier reseller sees our discounted prices.
     *
     * @param array<string, mixed> $upline
     * @return array<int, array<string, mixed>>
     */
    public function wholesaleServices(array $upline): array
    {
        $catalogue = $this->cost->serviceCatalogue();
        $flat = static fn (float $price): array => ['list' => $price, 'discount_percent' => 0.0, 'reseller' => $price];

        foreach ($catalogue as $i => $product) {
            foreach ($product['cycles'] as $j => $cycle) {
                $price = $this->quoteProduct((float) $cycle['price']['list'], $upline, (int) $product['product_id'], (string) $cycle['cycle'])['retail'];
                $catalogue[$i]['cycles'][$j]['price'] = $flat($price);
                $catalogue[$i]['cycles'][$j]['setup_fee'] = $flat($this->retailPriceFor((float) $cycle['setup_fee']['list'], $upline));
            }
        }

        return $catalogue;
    }

    /**
     * @param array<string, mixed> $upline
     * @return array<int, array<string, mixed>> shaped like ResellerPricing::domainCatalogue()
     */
    public function wholesaleDomains(array $upline): array
    {
        $catalogue = $this->cost->domainCatalogue();

        foreach ($catalogue as $i => $row) {
            foreach (['register', 'transfer', 'renew'] as $which) {
                $price = $this->quoteDomain((float) $row[$which]['list'], $upline, (string) $row['tld'], $which)['retail'];
                $catalogue[$i][$which] = ['list' => $price, 'discount_percent' => 0.0, 'reseller' => $price];
            }
        }

        return $catalogue;
    }

    /**
     * The whole service catalogue at retail — the preview a reseller sees, and
     * (from Phase 3) the prices the storefront will charge. Built on
     * ResellerPricing::serviceCatalogue() so the two can never disagree about
     * what a cycle costs.
     *
     * @param array<string, mixed> $store
     * @return array<int, array<string, mixed>>
     */
    public function previewServices(array $store): array
    {
        $catalogue = $this->cost->serviceCatalogue();

        foreach ($catalogue as $i => $product) {
            foreach ($product['cycles'] as $j => $cycle) {
                $catalogue[$i]['cycles'][$j]['retail'] = $this->quoteProduct(
                    (float) $cycle['price']['list'],
                    $store,
                    (int) $product['product_id'],
                    (string) $cycle['cycle']
                );
            }
        }

        return $catalogue;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<int, array<string, mixed>>
     */
    public function previewDomains(array $store): array
    {
        $catalogue = $this->cost->domainCatalogue();

        foreach ($catalogue as $i => $row) {
            foreach (['register', 'transfer', 'renew'] as $which) {
                $catalogue[$i][$which . '_retail'] = $this->quoteDomain(
                    (float) $row[$which]['list'],
                    $store,
                    (string) $row['tld'],
                    $which
                );
            }
        }

        return $catalogue;
    }

    /**
     * Persists a complete price list.
     *
     * A null value in either set means "no override — use the markup", which is
     * how a cleared form field reaches the repository.
     *
     * @param array<string, float|null> $productPrices keyed "{productId}:{cycle}"
     * @param array<string, array{register: ?float, transfer: ?float, renew: ?float}> $domainPrices keyed by TLD
     */
    public function saveOverrides(int $storeId, array $productPrices, array $domainPrices): void
    {
        $this->overrides->replaceProductOverrides($storeId, $productPrices);
        $this->overrides->replaceDomainOverrides($storeId, $domainPrices);

        // Drop the per-request cache so a caller that reads prices back in the
        // same request sees what it just wrote.
        unset($this->productCache[$storeId], $this->domainCache[$storeId]);
    }

    /**
     * What the reseller owes us for one catalogue figure: list less the admin's
     * discount. Exposed because the cart has to compute the cost side of a line
     * it is pricing at retail, from the same catalogue lookup.
     */
    public function costPriceFor(float $listPrice, string $kind): float
    {
        return $this->cost->resellerPrice($listPrice, $kind);
    }

    /**
     * A price the reseller has set that no longer covers what they owe us.
     *
     * Reported, never corrected: the customer must be charged what the reseller
     * advertised, and silently raising it would change a price the reseller
     * published. The reseller owes cost either way.
     */
    public function belowCost(float $retail, float $cost): bool
    {
        return $retail < $cost;
    }

    /**
     * Why an override cannot be saved, or null when it can.
     *
     * Only the floor is enforced. A reseller may of course charge anything
     * ABOVE cost — that is the point of the feature.
     */
    public function overrideProblem(float $price, float $cost, string $what): ?string
    {
        if ($price <= 0) {
            return $what . ' must be more than zero. Leave it blank to use your store-wide markup instead.';
        }

        if ($price < $cost) {
            return $what . ' of ' . number_format($price, 2) . ' is below the '
                . number_format($cost, 2) . ' you pay us for it — you would owe more than you collect. '
                . 'Leave it blank to use your markup, or raise it.';
        }

        return null;
    }

    /** @param array<string, mixed> $store @return array<string, float> */
    private function productOverrides(array $store): array
    {
        $id = (int) ($store['id'] ?? 0);

        return $this->productCache[$id] ??= ($id === 0 ? [] : $this->overrides->productOverrides($id));
    }

    /** @param array<string, mixed> $store @return array<string, array{register: ?float, transfer: ?float, renew: ?float}> */
    private function domainOverrides(array $store): array
    {
        $id = (int) ($store['id'] ?? 0);

        return $this->domainCache[$id] ??= ($id === 0 ? [] : $this->overrides->domainOverrides($id));
    }
}
