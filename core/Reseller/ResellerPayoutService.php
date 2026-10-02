<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;
use Throwable;

/**
 * Payout requests: who may ask for what, and what happens when an admin answers.
 *
 * THE ONE DECISION THAT SHAPES THIS FILE: THE ACCOUNT IS DEBITED WHEN THE REQUEST
 * IS MADE, NOT WHEN IT IS PAID.
 *
 * Debiting at payment would be the more literal reading of "money moved", and it
 * is wrong here. Between the request and the admin's answer, a pending request that
 * has not touched the balance is money the reseller can still spend or ask for
 * again — so double-spending becomes possible exactly when an admin is slow, which
 * is when a queue is longest. Debiting at request makes the second request fail on
 * ARITHMETIC (the withdrawable figure it would be checked against is already
 * reduced) rather than on a rule someone has to remember to enforce.
 *
 * The database enforces the rule as well — a unique key permits at most one
 * pending request per reseller, so a bypass of the check below still cannot
 * double-spend (migration 0191). Two independent defences on the same property,
 * and the one that is a constraint cannot be forgotten in a new code path.
 *
 * The consequence to accept: a pending request makes the balance look lower than
 * the money in the bank until it is decided. That is honest — the funds are
 * committed, and the request's status is shown next to them — and it is the same
 * way a card authorisation behaves.
 *
 * NOTHING HERE MOVES MONEY. Payment is a manual bank transfer an admin makes
 * outside the system and then records, with a reference. That is deliberate (plan
 * §9 item 8): the first version of anything that pays out should be a human
 * pressing a button, so the failure mode is a wrong reference rather than a wrong
 * transfer.
 */
final class ResellerPayoutService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** The human label for each state, so a view never invents one. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Awaiting payment',
        self::STATUS_PAID => 'Paid',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public function __construct(
        private readonly ResellerPayoutRepository $payouts,
        private readonly ResellerLedgerRepository $ledger,
        private readonly ResellerLedgerService $accounts,
        private readonly CurrencyService $currency,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * A reseller asks for everything they can withdraw right now.
     *
     * The whole withdrawable balance, not an amount of their choosing. A partial
     * payout leaves dust behind and turns "one open request" into "how much of my
     * balance is spoken for", which is a second balance to keep in step — and the
     * one thing this design refuses to do is keep a second balance. If a reseller
     * wants a smaller transfer, the honest answer is that the minimum already
     * decides whether a transfer is worth making at all.
     *
     * $destination is the store's bank details, passed in by the caller rather
     * than looked up here, and it is only ever READ — describe()ed onto the row as
     * an immutable snapshot, so a payout records where the money was sent even
     * after the reseller moves bank. Whether a destination must exist at all is a
     * workflow decision and is enforced by the client-facing controller; this
     * method's contract is about money, not paperwork.
     *
     * @param array<string, mixed>|null $destination
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    public function request(int $resellerId, ?string $now = null, ?array $destination = null): array
    {
        $now ??= $this->now();
        $account = $this->accounts->accountFor($resellerId, $now);

        if ($account === null) {
            return $this->fail('That store no longer exists.');
        }

        if ($this->payouts->openForReseller($resellerId) !== null) {
            return $this->fail('You already have a payout request awaiting payment.');
        }

        $amountBase = (float) $account['withdrawable_base'];

        if ($amountBase <= 0.0) {
            return $this->fail(
                'Nothing is available to withdraw yet. A sale becomes available '
                . $this->holdingPhrase((int) $account['holding_days']) . '.'
            );
        }

        if ($amountBase < (float) $account['minimum_base']) {
            return $this->fail(
                'The minimum payout is ' . $this->money((float) $account['minimum'], (string) $account['currency_code'])
                . ', and ' . $this->money((float) $account['withdrawable'], (string) $account['currency_code'])
                . ' is available. The money is yours — it is just not worth a transfer yet.'
            );
        }

        $currency = is_array($account['currency'] ?? null) ? $account['currency'] : [];
        $rate = $this->currency->rateFor($currency);

        try {
            $payoutId = $this->payouts->create([
                'reseller_id' => $resellerId,
                'client_id' => $account['store']['client_id'] ?? null,
                'amount' => $this->currency->convert($amountBase, $rate),
                'currency_id' => $currency['id'] ?? null,
                'currency_rate' => $rate,
                'amount_base' => $amountBase,
                'status' => self::STATUS_PENDING,
                'method' => self::METHOD_BANK_TRANSFER,
                'reference' => null,
                'note' => null,
                // The destination as it reads NOW, frozen onto the row. If the
                // reseller moves bank later, this payout's record still says
                // where the money actually went (payout plan §8 Phase D).
                'destination_snapshot' => ResellerPayoutDestinationRepository::describe($destination),
                'requested_at' => $now,
                'decided_at' => null,
                'decided_by' => null,
            ]);
        } catch (Throwable $e) {
            // The unique key on (reseller_id, open_flag) is the real guard against
            // a second concurrent request; the check above is only there to give a
            // good message in the ordinary case. Reaching here means two requests
            // were made at the same moment, and the second one lost -- which is
            // exactly what should happen, so it is reported as an ordinary refusal
            // rather than as a failure.
            return $this->fail('You already have a payout request awaiting payment.');
        }

        // Debited NOW, so the funds cannot be requested twice. See the class
        // docblock: this is the whole reason double-spending is impossible here.
        $this->ledger->append([
            'reseller_id' => $resellerId,
            'client_id' => $account['store']['client_id'] ?? null,
            'kind' => 'payout',
            'amount' => -1 * $amountBase,
            'withdrawable_at' => null,
            'order_id' => null,
            'invoice_id' => null,
            'payout_id' => $payoutId,
            'description' => 'Payout request #' . $payoutId . ' — set aside pending payment',
            'admin_id' => null,
            'created_at' => $now,
        ]);

        $payout = $this->payouts->find($payoutId);

        // Fired LAST, so a listener sees the settled state: the payout row and the
        // debit both exist, and either can be linked to. Every refusal above returns
        // before this point, so nothing is announced for a request that was refused —
        // a notification that fires on failures trains its recipients to ignore it.
        $this->hooks->fire(HookPoints::RESELLER_PAYOUT_REQUESTED, [
            'payoutId' => $payoutId,
            'resellerId' => $resellerId,
        ]);

        return $this->ok($payout);
    }

    /**
     * The admin has made the transfer and is recording it.
     *
     * A reference is REQUIRED, and that is not a formality: a manual transfer that
     * cannot be tied to a bank line is unauditable, and the only way to know
     * whether a reseller was actually paid is the reference. Refusing to record a
     * payment without one costs an admin ten seconds and is the difference between
     * a payout history and a list of intentions.
     *
     * Nothing is written to the ledger — the account was debited when the request
     * was made, and debiting again here would take the money twice.
     *
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    public function markPaid(int $payoutId, string $reference, ?int $adminId, ?string $now = null): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return $this->fail('A payment reference is required — it is the only thing that ties this payout to the bank line.');
        }

        return $this->decide($payoutId, self::STATUS_PAID, $reference, null, $adminId, $now);
    }

    /**
     * Refuse the request and return the funds to the reseller's account.
     *
     * The reversal is an APPENDED entry, never an edit or a delete: the request
     * happened, it was refused, and both facts belong in the record. Consistent
     * with how refunds work on the receipt side.
     *
     * The returned funds are immediately withdrawable, not re-held. They had
     * already cleared the holding period when they were requested; a refusal is
     * about the payment, not about the chargeback window, and re-holding them would
     * punish the reseller for our decision.
     *
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    public function reject(int $payoutId, ?string $note, ?int $adminId, ?string $now = null): array
    {
        return $this->decide($payoutId, self::STATUS_REJECTED, null, $note, $adminId, $now);
    }

    /**
     * A reseller withdraws their own request before it is paid.
     *
     * Owned requests only: the reseller id is compared against the row rather than
     * trusted from the session, so one reseller cannot cancel another's request by
     * editing a posted id. Cancelling and rejecting are the same thing from the
     * account's point of view, and differ only in who decided.
     *
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    public function cancel(int $payoutId, int $resellerId, ?string $now = null): array
    {
        $payout = $this->payouts->find($payoutId);

        if ($payout === null) {
            return $this->fail('That payout request no longer exists.');
        }

        if ((int) $payout['reseller_id'] !== $resellerId) {
            return $this->fail('That payout request does not belong to this account.');
        }

        return $this->decide($payoutId, self::STATUS_CANCELLED, null, 'Cancelled by the reseller.', null, $now);
    }

    /**
     * Everything the reseller's own page needs: the open request and the history.
     *
     * @return array{open: array<string, mixed>|null, history: array<int, array<string, mixed>>}
     */
    public function summaryFor(int $resellerId): array
    {
        return [
            'open' => $this->payouts->openForReseller($resellerId),
            'history' => $this->payouts->forReseller($resellerId),
        ];
    }

    /**
     * The admin queue and the running totals behind it.
     *
     * @return array{pending: array<int, array<string, mixed>>, decided: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function adminSummary(): array
    {
        return [
            'pending' => $this->payouts->pending(),
            'decided' => $this->payouts->decided(),
            'totals' => $this->payouts->totalsByStatus(),
        ];
    }

    /** Whether a given status is one this class will ever write. */
    public static function isKnownStatus(string $status): bool
    {
        return array_key_exists($status, self::STATUS_LABELS);
    }

    /**
     * Apply a decision, and return the funds if it was a refusal.
     *
     * The single place a payout changes state, so the ledger side-effect cannot be
     * forgotten in one of the three callers.
     *
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    private function decide(
        int $payoutId,
        string $status,
        ?string $reference,
        ?string $note,
        ?int $adminId,
        ?string $now
    ): array {
        $payout = $this->payouts->find($payoutId);

        if ($payout === null) {
            return $this->fail('That payout request no longer exists.');
        }

        if ((string) $payout['status'] !== self::STATUS_PENDING) {
            return $this->fail(
                'That request was already ' . strtolower(self::STATUS_LABELS[(string) $payout['status']] ?? (string) $payout['status'])
                . ' — nothing was changed.'
            );
        }

        $now ??= $this->now();

        // Guarded in the WHERE clause as well as above, so a second admin deciding
        // the same request in the same moment loses cleanly instead of overwriting
        // the first decision.
        if (!$this->payouts->decide($payoutId, $status, $reference, $note, $adminId, $now)) {
            return $this->fail('That request was decided by someone else — nothing was changed.');
        }

        if ($status !== self::STATUS_PAID) {
            $this->returnFunds($payout, $now);
        }

        return $this->ok($this->payouts->find($payoutId));
    }

    /**
     * Put a refused request's money back, as an appended entry.
     *
     * @param array<string, mixed> $payout
     */
    private function returnFunds(array $payout, string $now): void
    {
        $payoutId = (int) $payout['id'];

        $this->ledger->append([
            'reseller_id' => (int) $payout['reseller_id'],
            'client_id' => $payout['client_id'] === null ? null : (int) $payout['client_id'],
            // An adjustment rather than a positive payout: money coming BACK is a
            // different kind of event from money going out, and filing it under
            // "Paid out" would make that total read as something that never
            // happened. The description carries the detail.
            'kind' => 'adjustment',
            'amount' => abs((float) $payout['amount_base']),
            // Immediately withdrawable: these funds already cleared the holding
            // period when they were requested.
            'withdrawable_at' => null,
            'order_id' => null,
            'invoice_id' => null,
            'payout_id' => $payoutId,
            'description' => 'Payout request #' . $payoutId . ' returned to the account',
            'admin_id' => null,
            'created_at' => $now,
        ]);
    }

    /** @return array{ok: bool, error: string|null, payout: array<string, mixed>|null} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'payout' => null];
    }

    /**
     * @param array<string, mixed>|null $payout
     * @return array{ok: bool, error: string|null, payout: array<string, mixed>|null}
     */
    private function ok(?array $payout): array
    {
        return ['ok' => true, 'error' => null, 'payout' => $payout];
    }

    private function holdingPhrase(int $days): string
    {
        return $days === 0
            ? 'as soon as it is paid'
            : $days . ' days after your customer pays';
    }

    private function money(float $amount, string $code): string
    {
        return number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
