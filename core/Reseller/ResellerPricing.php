<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Catalog\BillingCycle;
use CodeVault\Database;

/**
 * What a reseller pays, and what they can resell.
 *
 * Every figure here is a CATALOG figure, in the catalog's own currency — the
 * same units `product_pricing.price` and `domain_pricing.register_price` are
 * stored in. Converting for display is CurrencyService's job (catalogRate()),
 * not this class's: mixing the two is what once quoted a ₦22,350 plan as
 * $22,350, and applying a percentage to an already-converted number would
 * reintroduce exactly that class of bug.
 *
 * The discount is a straight percentage off list, rounded to the cent, and it
 * is applied per item: services at the service percentage, domains at the
 * domain percentage, because they are priced from different tables and carry
 * different margins.
 */
final class ResellerPricing
{
    public function __construct(
        private readonly Database $db,
        private readonly ResellerSettings $settings
    ) {
    }

    public function discountFor(string $kind): float
    {
        return $this->settings->discountFor($kind);
    }

    /** List price less the reseller's percentage, to the cent. */
    public function resellerPrice(float $listPrice, string $kind): float
    {
        return round($listPrice * (1 - $this->discountFor($kind) / 100), 2);
    }

    /**
     * Both halves of the sum, so a page (or an API client) can show the
     * discount as well as the resulting price rather than presenting a figure
     * the reseller has to reverse-engineer.
     *
     * @return array{list: float, discount_percent: float, reseller: float}
     */
    public function quote(float $listPrice, string $kind): array
    {
        return [
            'list' => round($listPrice, 2),
            'discount_percent' => $this->discountFor($kind),
            'reseller' => $this->resellerPrice($listPrice, $kind),
        ];
    }

    /**
     * Every sellable service, with each billing cycle priced at list and at the
     * reseller's rate. Two queries rather than one per product.
     *
     * @return array<int, array{product_id: int, name: string, cycles: array<int, array<string, mixed>>}>
     */
    public function serviceCatalogue(): array
    {
        $products = $this->db->select(
            "SELECT p.id, p.name
             FROM products p
             JOIN product_pricing pp ON pp.product_id = p.id
             WHERE COALESCE(p.pay_type, 'paid') <> 'free'
             GROUP BY p.id, p.name
             ORDER BY p.name"
        );

        if ($products === []) {
            return [];
        }

        $pricingRows = $this->db->select('SELECT product_id, billing_cycle, price, setup_fee FROM product_pricing');
        $byProduct = [];

        foreach ($pricingRows as $row) {
            $byProduct[(int) $row['product_id']][] = $row;
        }

        $labels = BillingCycle::labels();
        $catalogue = [];

        foreach ($products as $product) {
            $id = (int) $product['id'];
            $cycles = [];

            foreach ($byProduct[$id] ?? [] as $row) {
                $cycle = (string) $row['billing_cycle'];
                $listPrice = (float) $row['price'];
                $listSetup = (float) $row['setup_fee'];

                $cycles[] = [
                    'cycle' => $cycle,
                    'label' => $labels[$cycle] ?? $cycle,
                    'price' => $this->quote($listPrice, 'service'),
                    'setup_fee' => $this->quote($listSetup, 'service'),
                ];
            }

            $catalogue[] = [
                'product_id' => $id,
                'name' => (string) $product['name'],
                'cycles' => $cycles,
            ];
        }

        return $catalogue;
    }

    /**
     * Every TLD on the price list, with each of the three things a domain can
     * cost a reseller (register, transfer, renew) quoted at list and reseller
     * rates.
     *
     * @return array<int, array{tld: string, register: array<string, mixed>, transfer: array<string, mixed>, renew: array<string, mixed>}>
     */
    public function domainCatalogue(): array
    {
        $rows = $this->db->select('SELECT tld, register_price, transfer_price, renew_price FROM domain_pricing ORDER BY tld');
        $catalogue = [];

        foreach ($rows as $row) {
            $catalogue[] = [
                'tld' => (string) $row['tld'],
                'register' => $this->quote((float) $row['register_price'], 'domain'),
                'transfer' => $this->quote((float) $row['transfer_price'], 'domain'),
                'renew' => $this->quote((float) $row['renew_price'], 'domain'),
            ];
        }

        return $catalogue;
    }
}
