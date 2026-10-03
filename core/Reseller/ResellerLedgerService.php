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
     * The whole account, oldest first, for the CSV export.
     *
     * A passthrough rather than a policy of its own: the export shows the same
     * entries the account page shows, with no conversion and no total, so there
     * is nothing to decide here that accountFor() has not already decided. It
     * exists so the controller never touches the repository directly, which is
     * the same rule every other reader of this account follows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entriesForExport(int $resellerId): array
    {
        return $this->ledger->entriesForExport($resellerId);
    }

    /**
     * One reseller's account for a period: the opening balance, the entries, the
     * closing balance, and the withdrawable figure as at the period end (§10.2).
     *
     * Derived ENTIRELY from the ledger, so there is no snapshot to keep in step.
     * The opening balance is a QUERY — everything posted before the period — and
     * not a stored figure, for the same reason the balance itself is not stored:
     * a number that is both recorded and derivable will eventually disagree with
     * itself, and a statement that disagrees with its own ledger is worse than no
     * statement at all.
     *
     * THE FIGURES ARE IN BASE UNITS, AND ONLY THE CLOSING BALANCE IS ALSO SHOWN IN
     * THE RESELLER'S CURRENCY. Converting each line would imply that every line was
     * settled at that rate, which is precisely what the reseller-carries-the-FX
     * decision denies — a per-line conversion would be a false claim rather than a
     * helpful extra, so the conversion is deliberately limited to the two closing
     * totals.
     *
     * The withdrawable figure is taken AT THE PERIOD END, so a receipt that
     * matures after the period is not presented as having been available during it.
     *
     * Returns null for a store that does not exist, like accountFor().
     *
     * @return array<string, mixed>|null
     */
    public function statementFor(int $resellerId, string $from, string $to): ?array
    {
        $store = $this->stores->find($resellerId);

        if ($store === null) {
            return null;
        }

        $client = $this->clients->find((int) $store['client_id']);
        $currency = $this->currency->resolveForClient($client);

        $opening = $this->ledger->balanceBefore($resellerId, $from);
        $entries = $this->ledger->entriesBetween($resellerId, $from, $to);

        $credits = 0.0;
        $debits = 0.0;
        $running = [];
        $accumulated = $opening;

        // Walked oldest-first, so each row shows the balance as it stood on that
        // day. Computing it top-down would print a balance that never existed.
        foreach ($entries as $entry) {
            $amount = (float) $entry['amount'];
            $accumulated += $amount;

            if ($amount < 0) {
                $debits += $amount;
            } else {
                $credits += $amount;
            }

            $running[(int) $entry['id']] = $accumulated;
        }

        $closing = round($accumulated, 2);
        $withdrawable = $this->ledger->withdrawableBalance($resellerId, $to);

        return [
            'reseller_id' => $resellerId,
            'store' => $store,
            'client' => $client,
            'currency' => $currency,
            'currency_code' => (string) ($currency['code'] ?? ''),
            'from' => $from,
            'to' => $to,

            'opening_base' => $opening,
            'entries' => $entries,
            'running' => $running,
            'entry_count' => count($entries),
            'credits_base' => round($credits, 2),
            'debits_base' => round($debits, 2),
            'closing_base' => $closing,

            // As at the period end — not as at today, which would let a later
            // maturity date make a past period look more available than it was.
            'withdrawable_base' => $withdrawable,

            // The closing figures only, deliberately. See the method docblock.
            'closing' => $this->inResellerCurrency($closing, $currency),
            'withdrawable' => $this->inResellerCurrency($withdrawable, $currency),

            'holding_days' => $this->holdingDays(),
            'in_arrears' => $closing < 0.0,
        ];
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
     * Reverse part or all of a store receipt, because the sale was refunded
     * (plan §9 decision 4: refunds append a reversing entry, they never edit the
     * original). Returns the new entry id, or null when there is nothing to reverse.
     *
     * THE REVERSAL IS WITHDRAWABLE IMMEDIATELY, AND THAT IS THE WHOLE POINT.
     *
     * The holding period exists to cover the card chargeback window on money we are
     * HOLDING. A refund is money we no longer hold, so it has to reduce the
     * withdrawable figure at once rather than being held again for thirty days.
     * Getting this wrong is not a display bug: the receipt would stay withdrawable
     * and the reseller could be PAID OUT money we have already returned to the
     * customer, with no entry left to claw back against.
     *
     * WHY IT IS BOUNDED BY THE ORDER'S NET
     *
     * Reversals are deliberately un-capped by the unique key, because a sale can be
     * refunded in several parts. That makes the caller responsible for the ceiling,
     * and the ceiling is the order's net: a refund may take it to zero but never
     * below, or we would be debiting the reseller for money already given back. A
     * hook that fires twice, or an over-large refund, therefore stops at the amount
     * actually credited rather than silently inverting the account.
     *
     * Returns null when the invoice belongs to no store, when no receipt was ever
     * posted (never paid, or refunded before the payment landed), or when the order
     * has already been reversed in full.
     */
    public function reverseStoreReceipt(int $invoiceId, float $refundedAmount, ?string $now = null): ?int
    {
        $facts = $this->ledger->storeOrderForInvoice($invoiceId);

        if ($facts === null) {
            return null;
        }

        $orderId = (int) $facts['order_id'];

        // Nothing was credited, so there is nothing to give back. Checking the
        // RECEIPT rather than the invoice's status is deliberate: the receipt is the
        // thing being reversed, and it is absent whenever the money never arrived.
        if (!$this->ledger->hasEntryForOrder($orderId, 'store_receipt')) {
            return null;
        }

        // The ceiling is what is STILL CREDITED on the receipt side of this order, so
        // repeated partial refunds shrink it. See outstandingForOrder() for why this is
        // per kind rather than the order's whole net.
        $outstanding = $this->ledger->outstandingForOrder($orderId);

        if ($outstanding['receipt_outstanding'] <= 0.0) {
            return null;
        }

        // Converted with the SAME invoice facts the receipt used, so a full refund
        // cancels the original exactly instead of leaving a rounding tail behind.
        $amount = min(
            $this->baseAmount($refundedAmount, $facts['currency_id'], $facts['currency_rate']),
            $outstanding['receipt_outstanding']
        );

        if ($amount < 0.01) {
            return null;
        }

        return $this->ledger->append([
            'reseller_id' => (int) $facts['reseller_id'],
            'client_id' => $facts['reseller_client_id'] === null ? null : (int) $facts['reseller_client_id'],
            'kind' => 'receipt_reversal',
            // Negative: this reduces what we owe. The sign is what makes it a
            // reversal rather than a second credit.
            'amount' => -round($amount, 2),
            // NULL = immediate. See the docblock: this is the safety property.
            'withdrawable_at' => null,
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'payout_id' => null,
            'description' => 'Refund on store order #' . $orderId . ' — retail returned to the customer',
            'admin_id' => null,
            'created_at' => $now ?? $this->now(),
        ]);
    }

    /**
     * Reverse the COST side of a refunded sale: we no longer charge the reseller for an
     * order that un-wound (plan §9 decision 4, confirmed by the user 2026-10-01).
     *
     * THE MIRROR OF reverseStoreReceipt(), BUT NOT A SYMMETRIC CEILING.
     *
     * The receipt reversal reduces credits and is capped by the receipt still standing on
     * that order — receipts ARE per order, so that is a sum by order_id. This one reduces
     * a debit that is NOT per order: `recordCostInvoice()` posts one entry for a whole
     * cost invoice covering many orders, so there is no per-order amount to sum. The
     * authority is the order's LINE on that invoice, which is also the figure actually
     * billed, and the ceiling is that line less whatever has already been credited back.
     *
     * Both ceilings are per KIND rather than per sign, so neither depends on whether the
     * other reversal has already run. A ceiling taken from the order's whole net would
     * not be: with a receipt of 100 and a cost of 80 the order sits at +20, so a cost
     * reversal capped by the net would find no headroom at all and quietly leave the
     * account half-un-wound.
     *
     * Immediate (`withdrawable_at` NULL) for the same reason as the receipt reversal:
     * this is money we are no longer owed, and holding it again would let the reseller
     * withdraw against a cost that has already been given back.
     *
     * The figure comes from the invoice LINE, not from `orders.cost_total`: see
     * billedCostLineForOrder(). Returns null when the invoice belongs to no store, when
     * the order was never billed (an early refund — the exclusion means no line exists),
     * or when there is no headroom left.
     */
    public function reverseCostForInvoice(int $invoiceId, ?string $now = null): ?int
    {
        $facts = $this->ledger->storeOrderForInvoice($invoiceId);

        if ($facts === null) {
            return null;
        }

        $orderId = (int) $facts['order_id'];
        $line = $this->ledger->billedCostLineForOrder($orderId);

        if ($line === null) {
            // Never billed. Nothing to give back. If the refund arrived before the
            // configured billing period closed, that is the correct outcome, not a miss.
            return null;
        }

        $outstanding = $this->ledger->outstandingForOrder($orderId);

        // The LINE is the authority for how much cost this order carried, because the
        // debit itself is invoice-level and carries no order_id. So the ceiling is the
        // line, less whatever has already been credited back for this order.
        // The LINE is the authority for how much cost this order carried, because the
        // debit itself is invoice-level and carries no order_id. So the ceiling is the
        // line, less whatever has already been credited back for this order.
        $amount = $this->baseAmount($line['amount'], $line['currency_id'], $line['currency_rate'])
            - $outstanding['cost_reversed'];

        if ($amount < 0.01) {
            return null;
        }

        return $this->ledger->append([
            'reseller_id' => (int) $facts['reseller_id'],
            'client_id' => $facts['reseller_client_id'] === null ? null : (int) $facts['reseller_client_id'],
            'kind' => 'cost_reversal',
            // POSITIVE: this reduces what the reseller owes, where the original debit
            // was negative for the same reason.
            'amount' => round($amount, 2),
            'withdrawable_at' => null,
            'order_id' => $orderId,
            // The COST invoice, not the customer's — this entry is about the bill we
            // raised, and pointing it at the customer's invoice would make the two
            // sides of the account indistinguishable in the ledger.
            'invoice_id' => (int) $line['invoice_id'],
            'payout_id' => null,
            'description' => 'Cost refunded for store order #' . $orderId . ' — the sale was refunded',
            'admin_id' => null,
            'created_at' => $now ?? $this->now(),
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
        $minimum = $this->payoutMinimumForCurrency($currency);

        return [
            'reseller_id' => $resellerId,
            'store' => $store,
            'client' => $client,
            'currency' => $currency,
            'currency_code' => (string) ($currency['code'] ?? ''),

            // Base figures — the account's real unit.
            'balance_base' => $balance,
            'withdrawable_base' => $withdrawable,
            'minimum_base' => $minimum['minimum_base'],

            // The same numbers in the reseller's currency. These MOVE with the
            // rate; they are a conversion of the balance, not a promise of it.
            'balance' => $this->inResellerCurrency($balance, $currency),
            'withdrawable' => $this->inResellerCurrency($withdrawable, $currency),
            'minimum' => $minimum['minimum'],

            'holding_days' => $this->holdingDays(),
            'can_withdraw' => $withdrawable > 0.0 && $withdrawable >= (float) $minimum['minimum_base'],
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

    /** The legacy fallback minimum, in base units, retained for currencies not configured individually. */
    public function payoutMinimum(): float
    {
        return max(0.0, (float) $this->settings->get('reseller.payout_minimum', '50.00'));
    }

    /**
     * The minimum expressed in both the account's base unit and the reseller's
     * currency. Explicit per-currency settings are nominal amounts in that
     * currency; an unconfigured currency keeps the legacy behavior by converting
     * the old base-unit minimum at the current rate.
     *
     * @param array<string, mixed> $currency
     * @return array{minimum: float, minimum_base: float, currency_code: string, custom: bool}
     */
    public function payoutMinimumForCurrency(array $currency): array
    {
        $code = strtoupper(trim((string) ($currency['code'] ?? '')));
        $rate = $this->currency->rateFor($currency);
        $rate = $rate > 0.0 ? $rate : 1.0;
        $map = $this->payoutMinimumMap();

        if ($code !== '' && array_key_exists($code, $map)) {
            $minimum = $map[$code];

            return [
                'minimum' => $minimum,
                'minimum_base' => round($minimum / $rate, 6),
                'currency_code' => $code,
                'custom' => true,
            ];
        }

        $baseMinimum = $this->payoutMinimum();

        return [
            'minimum' => $this->currency->convert($baseMinimum, $rate),
            'minimum_base' => $baseMinimum,
            'currency_code' => $code,
            'custom' => false,
        ];
    }

    /**
     * Effective settings for the admin form. Values are shown in each currency;
     * for currencies without an explicit setting, the legacy base minimum is
     * converted at today's rate so saving the form does not silently change its
     * current behavior.
     *
     * @return array<int, array{currency_code: string, currency_name: string, minimum: float, minimum_base: float, custom: bool}>
     */
    public function payoutMinimums(): array
    {
        $minimums = [];

        foreach ($this->currency->all() as $currency) {
            $effective = $this->payoutMinimumForCurrency($currency);
            $minimums[] = [
                'currency_code' => $effective['currency_code'],
                'currency_name' => (string) ($currency['name'] ?? $currency['code'] ?? ''),
                'minimum' => $effective['minimum'],
                'minimum_base' => $effective['minimum_base'],
                'custom' => $effective['custom'],
            ];
        }

        return $minimums;
    }

    /** @return array<string, float> */
    private function payoutMinimumMap(): array
    {
        $decoded = json_decode((string) $this->settings->get('reseller.payout_minimums', '{}'), true);

        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $code => $amount) {
            $code = strtoupper(trim((string) $code));

            if ($code === '' || !is_numeric($amount) || !is_finite((float) $amount)) {
                continue;
            }

            $map[$code] = round(max(0.0, (float) $amount), 2);
        }

        return $map;
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
