<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * What a storefront can actually sell, shaped for the storefront's chrome and
 * home page: the SERVICES menu, the footer columns, the home page's featured
 * plans and its domain price chips.
 *
 * Three rules, each of which the previous storefront broke somewhere:
 *
 *  1. **Only categories with something to buy.** The old header listed every
 *     row in product_groups — including the internal "System" group that only
 *     carries the hidden domain-registration product (migration 0103) — so a
 *     visitor could click a menu item that led to an empty page.
 *
 *  2. **The price on the shelf is the price at checkout.** On a store every
 *     figure goes through ResellerRetailPricing::quoteProduct()/quoteDomain(),
 *     the same calls CartService makes, so a reseller's per-product override
 *     shows here exactly as it will be charged. (The /store listing used to
 *     apply the markup but ignore overrides, so the shelf and the cart could
 *     disagree.)
 *
 *  3. **Base-currency amounts out, formatting left to the caller.** Every amount
 *     returned is in the catalogue currency; views convert through the $money
 *     formatter like the rest of the storefront, so this class never has an
 *     opinion about exchange rates.
 *
 * Memoised per instance: the header, the home page and the footer all ask for
 * the categories during one request.
 */
final class StorefrontCatalogue
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $categories = null;

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $plans = [];

    public function __construct(
        private readonly Database $db,
        private readonly CurrentReseller $tenant,
        private readonly ?ResellerRetailPricing $retail = null
    ) {
    }

    /**
     * Every category with at least one active product, in the admin's order,
     * each with its plan count and the lowest starting price a customer of
     * THIS site would pay.
     *
     * @return array<int, array{id: int, name: string, description: string, product_count: int, starting_price: ?float, starting_cycle: ?string}>
     */
    public function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $rows = $this->db->select(
            "SELECT g.id, g.name, g.description, COUNT(p.id) AS product_count
               FROM product_groups g
               JOIN products p ON p.product_group_id = g.id AND p.status = 'active'
              GROUP BY g.id, g.name, g.description, g.sort_order
              ORDER BY g.sort_order, g.name"
        );

        $categories = [];

        foreach ($rows as $row) {
            $cheapest = null;
            $cheapestCycle = null;

            foreach ($this->plansFor((int) $row['id']) as $plan) {
                if ($cheapest === null || (float) $plan['starting_price'] < $cheapest) {
                    $cheapest = (float) $plan['starting_price'];
                    $cheapestCycle = (string) $plan['starting_cycle'];
                }
            }

            $categories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => trim((string) ($row['description'] ?? '')),
                'product_count' => (int) $row['product_count'],
                'starting_price' => $cheapest,
                'starting_cycle' => $cheapestCycle,
            ];
        }

        return $this->categories = $categories;
    }

    /**
     * The active plans in one category, priced for this site.
     *
     * A plan's "starting" price is its monthly price when it has one, otherwise
     * its cheapest cycle — the same choice the /store listing makes, so the two
     * pages quote the same figure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plansFor(int $groupId, ?int $limit = null): array
    {
        if (!isset($this->plans[$groupId])) {
            $products = $this->db->select(
                "SELECT * FROM products WHERE product_group_id = ? AND status = 'active' ORDER BY sort_order, name",
                [$groupId]
            );

            foreach ($products as $i => $product) {
                $pricing = $this->db->select(
                    'SELECT price, billing_cycle FROM product_pricing WHERE product_id = ? ORDER BY price ASC',
                    [(int) $product['id']]
                );

                if ($pricing === []) {
                    $products[$i]['starting_price'] = 0.0;
                    $products[$i]['starting_cycle'] = 'monthly';
                    continue;
                }

                $monthly = array_values(array_filter(
                    $pricing,
                    static fn (array $row): bool => $row['billing_cycle'] === 'monthly'
                ))[0] ?? $pricing[0];

                $products[$i]['starting_price'] = $this->productPrice(
                    (float) $monthly['price'],
                    (int) $product['id'],
                    (string) $monthly['billing_cycle']
                );
                $products[$i]['starting_cycle'] = (string) $monthly['billing_cycle'];
            }

            $this->plans[$groupId] = $products;
        }

        return $limit === null ? $this->plans[$groupId] : array_slice($this->plans[$groupId], 0, $limit);
    }

    /**
     * Up to $limit TLDs with the registration price a customer of this site
     * pays, in the admin's order.
     *
     * @return array<int, array{tld: string, price: float}>
     */
    public function domainPrices(int $limit = 6): array
    {
        $rows = $this->db->select(
            'SELECT tld, register_price FROM domain_pricing ORDER BY sort_order ASC, tld ASC LIMIT ' . max(1, $limit)
        );

        $prices = [];

        foreach ($rows as $row) {
            $tld = (string) $row['tld'];
            $prices[] = ['tld' => $tld, 'price' => $this->domainPrice((float) $row['register_price'], $tld)];
        }

        return $prices;
    }

    /** How many TLDs this site sells — a real number for the home page, not a slogan. */
    public function tldCount(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS n FROM domain_pricing');

        return (int) ($row['n'] ?? 0);
    }

    private function productPrice(float $list, int $productId, string $cycle): float
    {
        $store = $this->tenant->get();

        if ($store === null || $this->retail === null) {
            return round($list, 2);
        }

        return $this->retail->quoteProduct($list, $store, $productId, $cycle)['retail'];
    }

    private function domainPrice(float $list, string $tld): float
    {
        $store = $this->tenant->get();

        if ($store === null || $this->retail === null) {
            return round($list, 2);
        }

        return $this->retail->quoteDomain($list, $store, $tld, 'register')['retail'];
    }
}
