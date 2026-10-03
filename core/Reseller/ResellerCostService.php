<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use DateTimeImmutable;
use CodeVault\Clients\ClientRepository;

/**
 * The money rules for billing a store for the cost of the orders it took.
 *
 * Two things must not be got wrong here.
 *
 * **Currency.** orders.cost_total is in the CUSTOMER's order currency, and a
 * store's customers each choose their own, so a period's accrual is a mix of
 * currencies. The reseller is invoiced in their own account currency, so every
 * order is normalised through CurrencyService::toBase() — which reverses
 * whichever storage convention wrote it, denominated or locked — and then
 * converted once with the reseller's live rate. Conversion lives here and
 * nowhere else, so the report and the invoice cannot disagree about a figure.
 *
 * **Which periods are billable.** A configured calendar month or ISO week is
 * billable only once it has closed, and an accrual below the configured minimum
 * is carried forward into the next invoice rather than invoiced on its own. That
 * is why duePeriods() walks closed periods in order instead of selecting
 * "everything older than a cutoff":
 * a cron that runs late, twice, or after an outage produces exactly the same
 * invoices as one that ran on time.
 */
final class ResellerCostService
{
    public function __construct(
        private readonly ResellerCostRepository $costs,
        private readonly ResellerStoreRepository $stores,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * A stored order cost re-expressed in the reseller's own currency.
     *
     * @param array<string, mixed> $resellerCurrency the reseller client's currency row
     */
    public function inResellerCurrency(
        array $resellerCurrency,
        float $amount,
        ?int $orderCurrencyId,
        float $orderRate
    ): float {
        return $this->currency->convert(
            $this->currency->toBase($amount, $orderCurrencyId, $orderRate),
            $this->currency->rateFor($resellerCurrency)
        );
    }

    public const CADENCE_MONTHLY = 'monthly';
    public const CADENCE_WEEKLY = 'weekly';

    /**
     * Cost ready to invoice: every unbilled order in a CLOSED billing period,
     * grouped by store and period, oldest first. Weekly periods follow ISO-8601:
     * Monday 00:00 through the next Monday 00:00 (exclusive).
     *
     * A period whose accrual is zero, or below the minimum, is not billed: its
     * orders are carried into the next period that clears the bar, and the invoice
     * records every period it covers. Orders below a minimum that is never
     * cleared stay unbilled and visible in the report rather than disappearing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function duePeriods(
        string $today,
        float $minimum = 0.0,
        string $cadence = self::CADENCE_MONTHLY
    ): array {
        $cadence = $cadence === self::CADENCE_WEEKLY
            ? self::CADENCE_WEEKLY
            : self::CADENCE_MONTHLY;
        $cutoff = $this->periodStart($today, $cadence);

        $byStore = [];
        foreach ($this->costs->unbilledBefore($cutoff) as $order) {
            $byStore[(int) $order['reseller_id']][] = $order;
        }

        $due = [];

        foreach ($byStore as $storeId => $orders) {
            $context = $this->contextFor($storeId);

            if ($context === null) {
                continue;
            }

            // The SQL already ordered by created_at, so building these buckets
            // in encounter order leaves them oldest-first without re-sorting.
            // ISO week keys use the ISO week-year (`o`), which differs from the
            // calendar year for the first and last days of some years.
            $periods = [];
            foreach ($orders as $order) {
                $date = new DateTimeImmutable((string) $order['created_at']);
                $key = $cadence === self::CADENCE_WEEKLY
                    ? $date->format('o-\WW')
                    : $date->format('Y-m');
                $periods[$key][] = $order;
            }

            $carry = [];
            $carryPeriods = [];
            $carryAmount = 0.0;

            foreach ($periods as $periodKey => $periodOrders) {
                $amount = $carryAmount;
                $priced = [];

                foreach ($periodOrders as $order) {
                    // Each order's converted figure is kept on the row: the
                    // invoice's line items and their total then come from ONE
                    // conversion, so they cannot disagree by a cent.
                    $order['converted_cost'] = $this->inResellerCurrency(
                        $context['currency'],
                        (float) $order['cost_total'],
                        $order['currency_id'] === null ? null : (int) $order['currency_id'],
                        (float) $order['currency_rate']
                    );

                    $amount += $order['converted_cost'];
                    $priced[] = $order;
                }

                $amount = round($amount, 2);
                $covered = array_merge($carryPeriods, [$periodKey]);
                $combined = array_merge($carry, $priced);

                if ($amount <= 0.0 || $amount < $minimum) {
                    $carry = $combined;
                    $carryPeriods = $covered;
                    $carryAmount = $amount;
                    continue;
                }

                $due[] = [
                    'reseller_id' => (int) $storeId,
                    'client' => $context['client'],
                    'store' => $context['store'],
                    'currency' => $context['currency'],
                    'period' => $periodKey,
                    'cadence' => $cadence,
                    'covers' => $covered,
                    'orders' => $combined,
                    'amount' => $amount,
                ];

                $carry = [];
                $carryPeriods = [];
                $carryAmount = 0.0;
            }
        }

        return $due;
    }

    /** Start of the current (still-open) billing period, as a SQL datetime. */
    private function periodStart(string $today, string $cadence): string
    {
        $date = new DateTimeImmutable($today);

        if ($cadence === self::CADENCE_WEEKLY) {
            // ISO weekday 1 is Monday. Offset explicitly rather than relying on
            // locale or a relative-date phrase; this keeps week boundaries stable.
            $daysSinceMonday = (int) $date->format('N') - 1;
            $date = $date->modify('-' . $daysSinceMonday . ' days');
        } else {
            $date = $date->modify('first day of this month');
        }

        return $date->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    }

    /**
     * Per-store accrued cost in the reseller's own currency, billed and
     * unbilled, for the admin report and the reseller's own page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storeSummaries(?int $resellerId = null): array
    {
        $contexts = [];
        $summaries = [];

        foreach ($this->costs->totals($resellerId) as $total) {
            $storeId = (int) $total['reseller_id'];

            if (!array_key_exists($storeId, $contexts)) {
                $contexts[$storeId] = $this->contextFor($storeId);
            }

            $context = $contexts[$storeId];

            if ($context === null) {
                continue;
            }

            $amount = $this->inResellerCurrency(
                $context['currency'],
                (float) $total['cost_total'],
                $total['currency_id'] === null ? null : (int) $total['currency_id'],
                (float) $total['currency_rate']
            );

            if (!isset($summaries[$storeId])) {
                $summaries[$storeId] = [
                    'reseller_id' => $storeId,
                    'store' => $context['store'],
                    'client' => $context['client'],
                    'currency' => $context['currency'],
                    'currency_code' => (string) ($context['currency']['code'] ?? ''),
                    'order_count' => 0,
                    'accrued' => 0.0,
                    'billed' => 0.0,
                    'unbilled' => 0.0,
                ];
            }

            $summaries[$storeId]['order_count'] += (int) $total['order_count'];
            $summaries[$storeId]['accrued'] = round($summaries[$storeId]['accrued'] + $amount, 2);

            if ((int) $total['unbilled'] === 1) {
                $summaries[$storeId]['unbilled'] = round($summaries[$storeId]['unbilled'] + $amount, 2);
            } else {
                $summaries[$storeId]['billed'] = round($summaries[$storeId]['billed'] + $amount, 2);
            }
        }

        return array_values($summaries);
    }

    /**
     * Cost invoiced but unpaid, oldest first — the reseller's arrears.
     *
     * Nothing here suspends anything. A storefront going offline takes the
     * reseller's business with it, so that stays an explicit admin action.
     *
     * @return array<int, array<string, mixed>>
     */
    public function arrears(?int $resellerId = null): array
    {
        $rows = [];

        foreach ($this->costs->unpaidCostInvoices($resellerId) as $invoice) {
            $invoice['currency'] = $this->currency->resolveLocked(
                $invoice['currency_id'] === null ? null : (int) $invoice['currency_id']
            );
            $rows[] = $invoice;
        }

        return $rows;
    }

    /**
     * The store, its owning client and that client's currency — the three
     * things any conversion needs.
     *
     * @return array<string, mixed>|null null when the store or its client is gone
     */
    private function contextFor(int $storeId): ?array
    {
        $store = $this->stores->find($storeId);

        if ($store === null) {
            return null;
        }

        $client = $this->clients->find((int) $store['client_id']);

        if ($client === null) {
            return null;
        }

        return [
            'store' => $store,
            'client' => $client,
            'currency' => $this->currency->resolveForClient($client),
        ];
    }
}
