<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Mail\EmailDispatcher;
use Throwable;

/**
 * Bulk price updates across the catalogue (products, selected by category
 * and billing cycle) and domain pricing (selected by TLD) — with a price
 * review email to every client who SUBSCRIBES to what changed: a client is
 * notified when they hold a service on a changed product at one of the
 * changed cycles, or a domain under a changed extension. Clients with no
 * personal impact (their cycle/extension didn't change) are not emailed.
 *
 * Prices are per-row DECIMAL(10,2) in the base currency (product_pricing has
 * no currency dimension — display currency is converted at render time), so
 * one percent/fixed adjustment applies uniformly. Every new price is clamped
 * at zero and rounded to 2 decimals.
 *
 * Only EXISTING pricing rows are adjusted: a product/cycle pair with no row
 * simply isn't offered for that product (see migration 0022) and a bulk
 * update must not silently start selling a cycle the admin never priced.
 */
final class BulkPriceService
{
    /** @var array<int, string> domain_pricing columns the bulk update may touch */
    private const DOMAIN_FIELDS = ['register_price', 'transfer_price', 'renew_price'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly DomainPricingRepository $domainPricing,
        private readonly ?EmailDispatcher $mail = null
    ) {
    }

    /**
     * Adjust product prices.
     *
     * @param array<int, int> $productIds
     * @param array<int, string> $cycles BillingCycle keys; [] means every cycle
     * @return array{changes: array<int, array{kind: string, product_id: int, name: string, cycle: string, cycle_label: string, field: string, old: float, new: float}>, skipped: int}
     */
    public function updateProducts(
        array $productIds,
        string $mode,
        float $amount,
        string $direction,
        array $cycles,
        bool $includeSetup
    ): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn ($id) => $id > 0)));
        $cycles = $this->validCycles($cycles);

        if ($productIds === [] || $cycles === [] || $amount <= 0.0) {
            return ['changes' => [], 'skipped' => 0];
        }

        $names = [];
        foreach ($this->products->all() as $product) {
            $names[(int) $product['id']] = (string) $product['name'];
        }

        $labels = BillingCycle::labels();
        $changes = [];
        $skipped = 0;

        foreach ($productIds as $productId) {
            foreach ($this->pricing->forProduct($productId) as $cycle => $row) {
                if (!in_array($cycle, $cycles, true)) {
                    continue;
                }

                $oldPrice = (float) $row['price'];
                $oldSetup = (float) $row['setup_fee'];
                $newPrice = $this->adjust($oldPrice, $mode, $amount, $direction);
                $newSetup = $includeSetup ? $this->adjust($oldSetup, $mode, $amount, $direction) : $oldSetup;

                $this->pricing->setPricing($productId, (string) $cycle, $newSetup, $newPrice);

                $base = [
                    'kind' => 'product',
                    'product_id' => $productId,
                    'name' => $names[$productId] ?? ("Product #{$productId}"),
                    'cycle' => (string) $cycle,
                    'cycle_label' => $labels[$cycle] ?? (string) $cycle,
                ];

                if ($newPrice !== $oldPrice) {
                    $changes[] = $base + ['field' => 'price', 'old' => $oldPrice, 'new' => $newPrice];
                }

                if ($includeSetup && $newSetup !== $oldSetup) {
                    $changes[] = $base + ['field' => 'setup_fee', 'old' => $oldSetup, 'new' => $newSetup];
                }

                if ($newPrice === $oldPrice && (!$includeSetup || $newSetup === $oldSetup)) {
                    // e.g. a fixed decrease larger than the price itself clamps
                    // at zero over an already-zero price — nothing moved.
                    $skipped++;
                }
            }
        }

        return ['changes' => $changes, 'skipped' => $skipped];
    }

    /**
     * Adjust domain prices for the given TLDs (dotted or bare — normalised).
     *
     * @param array<int, string> $tlds
     * @param array<int, string> $fields subset of register_price/transfer_price/renew_price; [] means all three
     * @return array{changes: array<int, array{kind: string, tld: string, field: string, field_label: string, old: float, new: float}>, skipped: int}
     */
    public function updateDomainPricing(
        array $tlds,
        string $mode,
        float $amount,
        string $direction,
        array $fields
    ): array {
        $wanted = array_values(array_intersect($fields === [] ? self::DOMAIN_FIELDS : $fields, self::DOMAIN_FIELDS));
        $tlds = array_values(array_unique(array_filter(array_map(
            static fn (string $tld): string => '.' . ltrim(strtolower(trim($tld)), '.'),
            $tlds
        ), static fn (string $tld): bool => $tld !== '.')));

        if ($tlds === [] || $wanted === [] || $amount <= 0.0) {
            return ['changes' => [], 'skipped' => 0];
        }

        $fieldLabels = [
            'register_price' => 'Register',
            'transfer_price' => 'Transfer',
            'renew_price' => 'Renew',
        ];

        $changes = [];
        $skipped = 0;

        foreach ($tlds as $tld) {
            $row = $this->domainPricing->findByTld($tld);

            if ($row === null) {
                $skipped++;
                continue;
            }

            $moved = false;

            foreach ($wanted as $field) {
                $old = (float) $row[$field];
                $new = $this->adjust($old, $mode, $amount, $direction);

                if ($new === $old) {
                    continue;
                }

                $row[$field] = $new;
                $changes[] = [
                    'kind' => 'domain',
                    'tld' => $tld,
                    'field' => $field,
                    'field_label' => $fieldLabels[$field],
                    'old' => $old,
                    'new' => $new,
                ];
                $moved = true;
            }

            if ($moved) {
                // save() rewrites the whole row from the passed fields — the
                // SELECT * row already carries every column it needs.
                $this->domainPricing->save($row);
            } else {
                $skipped++;
            }
        }

        return ['changes' => $changes, 'skipped' => $skipped];
    }

    /**
     * Email one price-review notice to every client affected by the changes,
     * listing only the lines that apply to THEM. One email per client even
     * when both their products and their domains changed.
     *
     * @param array<int, array<string, mixed>> $productChanges updateProducts()'s changes
     * @param array<int, array<string, mixed>> $domainChanges updateDomainPricing()'s changes
     * @param array{services?: string, domains?: string} $urls client-area links for the notice
     * @return array{notified: int, no_impact: int, failed: int}
     */
    public function notifySubscribers(array $productChanges, array $domainChanges, array $urls = []): array
    {
        $notified = 0;
        $noImpact = 0;
        $failed = 0;

        if ($this->mail === null || ($productChanges === [] && $domainChanges === [])) {
            return ['notified' => $notified, 'no_impact' => $noImpact, 'failed' => $failed];
        }

        $priceByProductCycle = [];
        $setupByProductCycle = [];
        $productIds = [];

        foreach ($productChanges as $change) {
            $productIds[] = (int) $change['product_id'];

            if (($change['field'] ?? '') === 'price') {
                $priceByProductCycle[(int) $change['product_id']][(string) $change['cycle']] = $change;
            } elseif (($change['field'] ?? '') === 'setup_fee') {
                $setupByProductCycle[(int) $change['product_id']][(string) $change['cycle']] = $change;
            }
        }

        $changesByTld = [];
        $bareTlds = [];

        foreach ($domainChanges as $change) {
            $tld = (string) $change['tld'];
            $changesByTld[$tld][] = $change;
            $bareTlds[] = ltrim($tld, '.');
        }

        $subscriberIds = $this->subscribersOfProducts(array_values(array_unique($productIds)));
        $subscriberIds += $this->subscribersOfTlds(array_values(array_unique($bareTlds)));

        $subscriberIds = array_keys($subscriberIds);
        sort($subscriberIds);

        foreach ($subscriberIds as $clientId) {
            $client = $this->db->selectOne('SELECT * FROM clients WHERE id = ?', [$clientId]);

            if ($client === null || trim((string) ($client['email'] ?? '')) === '') {
                continue;
            }

            $productLines = $this->productLinesForClient(
                $clientId,
                array_keys($priceByProductCycle),
                array_keys($setupByProductCycle),
                $priceByProductCycle,
                $setupByProductCycle
            );
            $domainLines = $this->domainLinesForClient($clientId, array_keys($changesByTld), $changesByTld);

            // A subscriber whose own configuration didn't move (their cycle
            // wasn't repriced, their extension wasn't touched) gets nothing —
            // a notice with no personal impact is noise.
            if ($productLines === [] && $domainLines === []) {
                $noImpact++;
                continue;
            }

            $variables = [
                'first_name' => (string) ($client['first_name'] ?? ''),
                'effective_date' => date('j F Y'),
                'product_changes' => $productLines === []
                    ? ''
                    : '<p><strong>Hosting / product prices that affect you:</strong></p><ul>' . implode('', $productLines) . '</ul>',
                'domain_changes' => $domainLines === []
                    ? ''
                    : '<p><strong>Domain prices that affect you:</strong></p><ul>' . implode('', $domainLines) . '</ul>',
                'client_services_url' => (string) ($urls['services'] ?? ''),
                'client_domains_url' => (string) ($urls['domains'] ?? ''),
                'company_name' => brand_name(),
            ];

            try {
                $this->mail->sendTemplate('price_update_notice', (string) $client['email'], $variables, $clientId);
                $notified++;
            } catch (Throwable) {
                // A missing/edited template or mail outage must never undo
                // the price change itself — count it and carry on.
                $failed++;
            }
        }

        return ['notified' => $notified, 'no_impact' => $noImpact, 'failed' => $failed];
    }

    /**
     * Clients holding a live service on any of the products — pending, active
     * and suspended subscribers all pay these prices; cancelled/terminated
     * accounts don't and are excluded.
     *
     * @param array<int, int> $productIds
     * @return array<int, true> client id set
     */
    private function subscribersOfProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->db->select(
            "SELECT DISTINCT client_id FROM services WHERE product_id IN ({$placeholders}) AND status NOT IN ('cancelled', 'terminated')",
            $productIds
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['client_id']] = true;
        }

        return $out;
    }

    /**
     * Clients holding a live domain under any of the TLDs (bare form —
     * domains.tld stores no leading dot, domain_pricing stores one).
     *
     * @param array<int, string> $bareTlds
     * @return array<int, true> client id set
     */
    private function subscribersOfTlds(array $bareTlds): array
    {
        if ($bareTlds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bareTlds), '?'));
        $rows = $this->db->select(
            "SELECT DISTINCT client_id FROM domains WHERE tld IN ({$placeholders}) AND status NOT IN ('cancelled', 'transferred_away')",
            $bareTlds
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['client_id']] = true;
        }

        return $out;
    }

    /**
     * The changed price/setup lines that apply to THIS client's services —
     * only rows where their service's own billing cycle was repriced.
     *
     * @param array<int, int> $productIds
     * @param array<int, int> $setupProductIds
     * @param array<int, array<int|string, array<string, mixed>>> $priceByProductCycle
     * @param array<int, array<int|string, array<string, mixed>>> $setupByProductCycle
     * @return array<int, string> rendered <li> lines
     */
    private function productLinesForClient(
        int $clientId,
        array $productIds,
        array $setupProductIds,
        array $priceByProductCycle,
        array $setupByProductCycle
    ): array {
        $productIds = array_values(array_unique(array_merge($productIds, $setupProductIds)));

        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->db->select(
            "SELECT s.product_id, s.billing_cycle, p.name FROM services s JOIN products p ON p.id = s.product_id
             WHERE s.client_id = ? AND s.product_id IN ({$placeholders}) AND s.status NOT IN ('cancelled', 'terminated')",
            array_merge([$clientId], $productIds)
        );

        $lines = [];
        $seen = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $cycle = (string) $row['billing_cycle'];
            $key = $productId . ':' . $cycle;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $label = BillingCycle::labels()[$cycle] ?? $cycle;
            $parts = [];

            if (isset($priceByProductCycle[$productId][$cycle])) {
                $c = $priceByProductCycle[$productId][$cycle];
                $parts[] = number_format((float) $c['old'], 2) . ' &rarr; ' . number_format((float) $c['new'], 2);
            }

            if (isset($setupByProductCycle[$productId][$cycle])) {
                $c = $setupByProductCycle[$productId][$cycle];
                $parts[] = 'setup ' . number_format((float) $c['old'], 2) . ' &rarr; ' . number_format((float) $c['new'], 2);
            }

            if ($parts !== []) {
                $lines[] = '<li>' . e((string) $row['name']) . ' (' . $label . '): ' . implode(' &middot; ', $parts) . '</li>';
            }
        }

        return $lines;
    }

    /**
     * The changed TLD lines that apply to THIS client's domains.
     *
     * @param array<int, string> $tlds
     * @param array<string, array<int, array<string, mixed>>> $changesByTld
     * @return array<int, string> rendered <li> lines
     */
    private function domainLinesForClient(int $clientId, array $tlds, array $changesByTld): array
    {
        if ($tlds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tlds), '?'));
        $rows = $this->db->select(
            "SELECT domain_name, tld FROM domains WHERE client_id = ? AND tld IN ({$placeholders}) AND status NOT IN ('cancelled', 'transferred_away')",
            array_merge([$clientId], $tlds)
        );

        $lines = [];
        $seenTlds = [];

        foreach ($rows as $row) {
            $tld = '.' . ltrim(strtolower((string) $row['tld']), '.');

            if (isset($seenTlds[$tld]) || !isset($changesByTld[$tld])) {
                continue;
            }
            $seenTlds[$tld] = true;

            $parts = [];
            foreach ($changesByTld[$tld] as $change) {
                $parts[] = (string) $change['field_label'] . ' ' . number_format((float) $change['old'], 2) . ' &rarr; ' . number_format((float) $change['new'], 2);
            }

            $lines[] = '<li><strong>' . e((string) $row['domain_name']) . '</strong> (' . e($tld) . '): ' . implode(' &middot; ', $parts) . '</li>';
        }

        return $lines;
    }

    /**
     * One price adjustment: percentage of the old value or a flat amount,
     * up or down, clamped at zero, rounded to 2 decimals.
     */
    private function adjust(float $old, string $mode, float $amount, string $direction): float
    {
        $delta = $mode === 'percent' ? $old * $amount / 100 : $amount;
        $new = $direction === 'decrease' ? $old - $delta : $old + $delta;

        return round(max(0.0, $new), 2);
    }

    /**
     * @param array<int, string> $cycles
     * @return array<int, string>
     */
    private function validCycles(array $cycles): array
    {
        $keys = BillingCycle::keys();

        if ($cycles === []) {
            return $keys;
        }

        return array_values(array_intersect($cycles, $keys));
    }
}
