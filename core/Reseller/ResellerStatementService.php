<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Settings\SettingsRepository;

/**
 * Issuing a numbered statement — the moment a reading of the account becomes a
 * document (payout plan §10.2).
 *
 * THE ONE IDEA IN THIS CLASS: A VIEW IS RECOMPUTED, A DOCUMENT IS FROZEN.
 *
 * ResellerLedgerService::statementFor() answers "what does this period look like
 * NOW". That is exactly right for a page and exactly wrong for a document, because
 * a reading can change underneath it — a late-arriving entry, a corrected one, a
 * back-dated cost invoice. If two people open "statement 12" a week apart and get
 * different numbers, then nothing has been stated at all.
 *
 * So issuing does two things in a specific order: it takes ONE reading, and it
 * records that reading in full — the five totals, every line, the rate used, and
 * our own identity as it stood that day. After that the document renders from what
 * was recorded, and the ledger is never consulted again for it. The point is not
 * that the numbers are correct; it is that they CANNOT CHANGE.
 *
 * THE IDENTITY IS FROZEN FOR THE SAME REASON THE TOTALS ARE
 *
 * A company that later changes its VAT number must not retroactively alter
 * documents it has already issued. Storing the identity as it was keeps "issued
 * by" tied to the facts recorded on the issue date, not whoever we are today.
 *
 * Nothing here moves money, and nothing here changes the ledger. Issuing a
 * statement is a read that happens to be written down.
 */
final class ResellerStatementService
{
    /**
     * The issuer identity fields carried on a statement, in the order they
     * should be shown and warned about. Labels live here so the checklist on the
     * page and the document itself cannot disagree about what is missing.
     */
    public const IDENTITY_FIELDS = [
        'legal_name' => 'Legal company name',
        'tax_number' => 'Tax / VAT registration number',
        'registration_number' => 'Company registration number',
        'address' => 'Registered address',
        'email' => 'Contact email',
        'phone' => 'Contact phone',
    ];

    public function __construct(
        private readonly ResellerStatementRepository $statements,
        private readonly ResellerLedgerService $ledger,
        private readonly SettingsRepository $settings,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * Issue the statement for a period, or hand back the one already issued.
     *
     * IDEMPOTENT BY DESIGN, and that is not a convenience: pressing the button
     * twice must not mint a second number for the same period, because a duplicate
     * number is indistinguishable from a lost document and would make the sequence
     * untrustworthy for exactly the audit it exists to serve. The check is the
     * (reseller_id, period_from, period_to) unique key — the data refuses it, and
     * this method asks the question first so the common case is a clean return
     * rather than an exception.
     *
     * Returns null when the store does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function issue(int $resellerId, string $from, string $to, ?int $adminId = null): ?array
    {
        $existing = $this->statements->forPeriod($resellerId, $from, $to);

        if ($existing !== null) {
            return $this->decode($existing) + ['already_issued' => true];
        }

        $view = $this->ledger->statementFor($resellerId, $from, $to);

        if ($view === null) {
            return null;
        }

        $store = is_array($view['store'] ?? null) ? $view['store'] : [];
        $currency = is_array($view['currency'] ?? null) ? $view['currency'] : [];
        $running = is_array($view['running'] ?? null) ? $view['running'] : [];

        $lines = [];

        foreach ((array) $view['entries'] as $entry) {
            $lines[] = [
                'created_at' => (string) $entry['created_at'],
                'kind' => (string) $entry['kind'],
                'amount' => (float) $entry['amount'],
                // The balance as it stood, frozen. Recomputing it at render time
                // would let a later entry renumber this one's history.
                'balance_after' => (float) ($running[(int) $entry['id']] ?? 0.0),
                'withdrawable_at' => $entry['withdrawable_at'] ?? null,
                'order_id' => $entry['order_id'] ?? null,
                'invoice_id' => $entry['invoice_id'] ?? null,
                'payout_id' => $entry['payout_id'] ?? null,
                'description' => (string) ($entry['description'] ?? ''),
            ];
        }

        // The year the number is scoped to is the year the period ENDS in: a period
        // of 20 Dec - 20 Jan is a January document, which is when it is issued and
        // when the reseller will file it.
        $created = $this->statements->create([
            'reseller_id' => $resellerId,
            'client_id' => $store['client_id'] ?? null,
            'period_year' => (int) substr($to, 0, 4),
            'period_from' => $from,
            'period_to' => $to,
            'opening_base' => (float) $view['opening_base'],
            'credits_base' => (float) $view['credits_base'],
            'debits_base' => (float) $view['debits_base'],
            'closing_base' => (float) $view['closing_base'],
            'withdrawable_base' => (float) $view['withdrawable_base'],
            'entry_count' => (int) $view['entry_count'],
            'currency_id' => $currency['id'] ?? null,
            'currency_code' => ((string) ($view['currency_code'] ?? '')) !== '' ? (string) $view['currency_code'] : null,
            'currency_rate' => $currency === [] ? 1.0 : $this->currency->rateFor($currency),
            'closing_converted' => (float) $view['closing'],
            'withdrawable_converted' => (float) $view['withdrawable'],
            'line_items' => $this->encode($lines),
            'our_identity' => $this->encode($this->identity()),
            'issued_at' => $this->now(),
            'issued_by' => $adminId,
        ]);

        $issued = $this->statements->find($created['id']);

        if ($issued === null) {
            return null;
        }

        return $this->decode($issued) + ['already_issued' => false];
    }

    /**
     * An issued statement, with its two JSON payloads decoded.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->statements->find($id);

        return $row === null ? null : $this->decode($row);
    }

    /**
     * A store's issued statements, newest first, decoded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listing(int $resellerId, int $limit = 100): array
    {
        return array_map(
            fn (array $row): array => $this->decode($row),
            $this->statements->forReseller($resellerId, $limit)
        );
    }

    /** @return array<string, mixed>|null */
    public function latest(int $resellerId): ?array
    {
        $row = $this->statements->latestFor($resellerId);

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Our issuer identity, as it stands right now.
     *
     * `company.name` is the legal name — it already exists and is what the rest of
     * the platform prints, so re-introducing a second "legal name" setting would
     * create two answers to one question.
     *
     * @return array<string, string>
     */
    public function identity(): array
    {
        $keys = [
            'legal_name' => 'company.name',
            'tax_number' => 'company.tax_number',
            'registration_number' => 'company.registration_number',
            'address' => 'company.address',
            'email' => 'company.email',
            'phone' => 'company.phone',
        ];

        $identity = [];

        foreach ($keys as $field => $setting) {
            $identity[$field] = trim((string) $this->settings->get($setting, ''));
        }

        return $identity;
    }

    /**
     * Which identity fields are still blank, as human labels.
     *
     * Exists so the page can say what is missing BEFORE a document is issued. The
     * snapshot is immutable, so filling in a missing field after issue cannot
     * silently rewrite a statement that has already gone out.
     *
     * @return array<int, string>
     */
    public function missingIdentity(): array
    {
        $missing = [];

        foreach ($this->identity() as $field => $value) {
            if ($value === '') {
                $missing[] = self::IDENTITY_FIELDS[$field] ?? $field;
            }
        }

        return $missing;
    }

    /**
     * Decode the frozen payloads for rendering.
     *
     * A payload that will not decode becomes an empty array rather than throwing:
     * the document must still render its TOTALS, which are typed columns and cannot
     * be corrupt. Losing the itemisation is bad; losing the document because one
     * JSON blob is unreadable is worse, and the totals are what the document asserts.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $row['lines'] = $this->decodeJson((string) ($row['line_items'] ?? ''));
        $row['identity'] = $this->decodeJson((string) ($row['our_identity'] ?? ''));

        return $row;
    }

    /** @return array<int|string, mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int|string, mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
