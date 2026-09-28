<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Settings\SettingsRepository;
use DateTimeImmutable;

/**
 * The money rules for a reseller's running account (payout plan §2).
 *
 * THE ONE THING TO UNDERSTAND HERE IS THE UNIT.
 *
 * The account is kept in the BASE currency. That is not an implementation
 * preference, it is the user's FX decision expressed in a schema: the RESELLER
 * carries the exchange movement between the day we collect a customer's payment
 * and the day we pay the reseller out. So every amount this class posts is a base
 * figure, frozen at the moment it is posted, and the reseller's own currency
 * figure is a CONVERSION of the balance — which is exactly why that figure moves
 * from day to day with no new sales at all.
 *
 * The alternative (crediting the reseller's currency at accrual) would have been
 * simpler and would have made their balance stable, i.e. it would have put the FX
 * risk on us. Do not "simplify" this back.
 *
 * There are two conversions in the reseller feature and they run in OPPOSITE
 * directions, which is why they live in two classes:
 *
 *   ResellerCostService::inResellerCurrency()  order currency -> reseller currency
 *                                              (what we BILL, one order at a time)
 *   this class                                 base           -> reseller currency
 *                                              (what we OWE, as a balance)
 *
 * Nothing here moves money. Phase A only writes entries and reads totals; the
 * withdrawable figure tells a reseller what a payout could draw on, and no payout
 * exists yet.
 */
final class ResellerLedgerService
{
    public function __construct(
        private readonly ResellerLedgerRepository $ledger,
        private readonly ResellerStoreRepository $stores,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency,
        private readonly SettingsRepository $settings
    ) {
    }

    /**
     * Post the retail we collected for a store order, when that order's invoice is
     * PAID. Returns the new entry id, or null when there is nothing to do.
     *
     * Null is the ordinary answer, not a failure: most paid invoices are ours and
     * belong to no store, and a re-fired hook for an invoice we already posted is
     * a no-op by design. Paid is the trigger because it is the moment we actually
     * hold the money — accruing at order placement would create a withdrawable
     * balance funded by money nobody has paid.
     */
    public function accrueStoreReceipt(int $invoiceId, ?string $now = null): ?int
    {
        $facts = $this->ledger->storeOrderForInvoice($invoiceId);

        if ($facts === null) {
            return null;
        }

        // The PAID state is checked here, not merely assumed from the hook that
        // calls us. The hook is the trigger; this is the guarantee. Without it a
        // direct call (an admin rebilling, a future backfill script) could credit
        // a balance funded by money nobody has paid, which is the exact failure
        // the accrual trigger was chosen to prevent.
        if ((string) ($facts['status'] ?? '') !== 'paid') {
            return null;
        }

        $orderId = (int) $facts['order_id'];

        if ($this->ledger->hasEntryForOrder($orderId, 'store_receipt')) {
            return null;
        }

        $now ??= $this->now();

        return $this->ledger->append([
            'reseller_id' => (int) $facts['reseller_id'],
            'client_id' => $facts['reseller_client_id'] === null ? null : (int) $facts['reseller_client_id'],
            'kind' => 'store_receipt',
            'amount' => $this->baseAmount($facts['total'], $facts['currency_id'], $facts['currency_rate']),
            'withdrawable_at' => $this->withdrawableFrom($now),
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'payout_id' => null,
            'description' => 'Store order #' . $orderId . ' — retail collected',
            'admin_id' => null,
            'created_at' => $now,
        ]);
    }

    /**
     * Post the debit side: the cost invoice Phase 4 raised for a store.
     *
     * The amount is the INVOICE's total, read from the invoice rather than
     * recomputed from the orders it billed. The invoice is the document the
     * reseller has been told they owe, so if a recomputation ever disagreed with
     * it the document would still be right — and "the ledger says I owe something
     * other than what you invoiced me" is not a conversation worth having.
     *
     * Two accepted consequences, both written down rather than discovered later:
     *
     * - **Tax is included.** Phase 4 bills cost + tax, so the total carries it, and
     *   this debits the reseller what we actually billed. Debatable if resellers
     *   are tax-registered and reclaim it; it is their invoice, so it is their figure.
     * - **The figure round-trips through the reseller's currency.** Phase 4 converts
     *   the order cost into the reseller's currency to write the invoice, and this
     *   converts that back to base. Where a rate is not an exact inverse, rounding
     *   at each step can move the base figure by a fraction of a cent.
     */
    public function recordCostInvoice(int $invoiceId, ?string $now = null): ?int
    {
        if ($this->ledger->hasCostEntryForInvoice($invoiceId)) {
            return null;
        }

        $resellerId = $this->ledger->resellerForCostInvoice($invoiceId);

        if ($resellerId === null) {
            return null;
        }

        $facts = $this->ledger->invoiceFacts($invoiceId);

        if ($facts === null) {
            return null;
        }

        $now ??= $this->now();

        // Negated: a cost reduces the balance, so it is stored as a negative
        // amount rather than as a positive one with a kind the reader must
        // remember to subtract. Balance is then always SUM(amount).
        return $this->ledger->append([
            'reseller_id' => $resellerId,
            'client_id' => $facts['client_id'] === null ? null : (int) $facts['client_id'],
            'kind' => 'cost_invoice',
            'amount' => -1 * abs($this->baseAmount($facts['total'], $facts['currency_id'], $facts['currency_rate'])),
            'withdrawable_at' => null,
            'order_id' => null,
            'invoice_id' => $invoiceId,
            'payout_id' => null,
            'description' => 'Cost invoice INV-' . $invoiceId . ' settled from balance',
            'admin_id' => null,
            'created_at' => $now,
        ]);
    }

    /**
     * Everything a reseller (or an admin looking at one) needs to see about their
     * account: the balance, how much of it is withdrawable, and the entries behind
     * both.
     *
     * Two figures, deliberately, because the 30-day holding period makes them
     * genuinely different numbers: "we owe you X" and "you can take Y today".
     *
     * @return array<string, mixed>|null null when the store is gone
     */
    public function accountFor(int $resellerId, ?string $asOf = null): ?array
    {
        $store = $this->stores->find($resellerId);

        if ($store === null) {
            return null;
        }

        $client = $this->clients->find((int) $store['client_id']);
        $currency = $this->currency->resolveForClient($client);
        $asOf ??= $this->now();

        $balance = $this->ledger->balance($resellerId);
        $withdrawable = $this->ledger->withdrawableBalance($resellerId, $asOf);
        $minimum = $this->payoutMinimum();

        return [
            'reseller_id' => $resellerId,
            'store' => $store,
            'client' => $client,
            'currency' => $currency,
            'currency_code' => (string) ($currency['code'] ?? ''),

            // Base figures — the account's real unit.
            'balance_base' => $balance,
            'withdrawable_base' => $withdrawable,
            'minimum_base' => $minimum,

            // The same numbers in the reseller's currency. These MOVE with the
            // rate; they are a conversion of the balance, not a promise of it.
            'balance' => $this->inResellerCurrency($balance, $currency),
            'withdrawable' => $this->inResellerCurrency($withdrawable, $currency),
            'minimum' => $this->inResellerCurrency($minimum, $currency),

            'holding_days' => $this->holdingDays(),
            'can_withdraw' => $withdrawable > 0.0 && $withdrawable >= $minimum,
            'in_arrears' => $balance < 0.0,
            'totals' => $this->ledger->totalsByKind($resellerId),
            'entries' => $this->ledger->entries($resellerId),
        ];
    }

    /**
     * Every store that has an account, for the admin report.
     *
     * Read in one grouped query, and never summed across stores: an NGN account
     * and a USD account have no common total.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaries(?string $asOf = null): array
    {
        $asOf ??= $this->now();
        $contexts = [];
        $rows = [];

        foreach ($this->ledger->balancesByStore($asOf) as $balance) {
            $storeId = (int) $balance['reseller_id'];

            if (!array_key_exists($storeId, $contexts)) {
                $contexts[$storeId] = $this->accountFor($storeId, $asOf);
            }

            $account = $contexts[$storeId];

            if ($account === null) {
                continue;
            }

            $account['entry_count'] = (int) $balance['entry_count'];
            $rows[] = $account;
        }

        return $rows;
    }

    /** The agreed holding period, in days. 0 means funds are withdrawable at once. */
    public function holdingDays(): int
    {
        return max(0, (int) $this->settings->get('reseller.payout_holding_days', '30'));
    }

    /** The agreed minimum, in BASE units (see the plan: one value for all currencies). */
    public function payoutMinimum(): float
    {
        return max(0.0, (float) $this->settings->get('reseller.payout_minimum', '50.00'));
    }

    /**
     * The base-currency equivalent of a stored document amount.
     *
     * This is the ONLY conversion on the posting path, and it is deliberately
     * `CurrencyService::toBase()` rather than arithmetic here: a denominated row
     * (currency_id set, rate 1.0) and a locked row (amount already base, rate is
     * display-only) need opposite treatment, and that reversal is already written
     * down once, in one place, with a bug attached to it.
     *
     * Calling it at posting time is also what PINS the rate: the result is stored,
     * so it cannot drift afterwards. That is why no exchange-rate column is needed
     * on the ledger.
     *
     * @param mixed $amount
     * @param mixed $currencyId
     * @param mixed $currencyRate
     */
    private function baseAmount($amount, $currencyId, $currencyRate): float
    {
        return $this->currency->toBase(
            (float) $amount,
            $currencyId === null ? null : (int) $currencyId,
            (float) ($currencyRate ?? 1.0)
        );
    }

    /** @param array<string, mixed> $currency */
    private function inResellerCurrency(float $baseAmount, array $currency): float
    {
        return $this->currency->convert($baseAmount, $this->currency->rateFor($currency));
    }

    /**
     * When a receipt posted at $now becomes withdrawable.
     *
     * Computed on the PHP side and stored, so the comparison at read time is
     * PHP-time against PHP-time. Mixing this with SQL's NOW() would measure the
     * interval across two clocks that do not agree — the same trap that made a
     * "24 hour" expiry measure 25.
     *
     * Null when there is no holding period, which is what makes the withdrawable
     * sum a simple IS NULL OR <= test.
     */
    private function withdrawableFrom(string $now): ?string
    {
        $days = $this->holdingDays();

        if ($days === 0) {
            return null;
        }

        $from = new DateTimeImmutable($now);

        return $from->modify("+{$days} days")->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
