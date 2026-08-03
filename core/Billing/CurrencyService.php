<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * Multi-currency display (blueprint §4.4/§5). Every stored monetary amount
 * (order totals, invoice totals, account credit) stays authoritative in
 * the system's base/default currency — this service only converts for
 * *display*, and orders/invoices lock the rate at creation time
 * (currency_id + currency_rate columns) so a historical document never
 * re-prices itself when today's exchange rate changes.
 */
final class CurrencyService
{
    public function __construct(
        private readonly CurrencyRepository $currencies
    ) {
    }

    /** @return array<string, mixed> */
    public function resolveForClient(?array $client): array
    {
        if ($client !== null && $client['currency_id'] !== null) {
            $currency = $this->currencies->find((int) $client['currency_id']);

            if ($currency !== null) {
                return $currency;
            }
        }

        return $this->currencies->default();
    }

    /**
     * The currency to display prices in *right now* — an explicit session
     * choice (works for guests browsing before login) wins over the
     * client's saved profile preference, which wins over the system
     * default. Used for live pricing (store/cart); never for historical
     * documents, which use resolveLocked() against their own locked rate.
     *
     * @return array<string, mixed>
     */
    public function resolveEffective(?array $client, ?int $sessionCurrencyId): array
    {
        if ($sessionCurrencyId !== null) {
            $currency = $this->currencies->find($sessionCurrencyId);

            if ($currency !== null) {
                return $currency;
            }
        }

        return $this->resolveForClient($client);
    }

    /**
     * The currency a historical order/invoice was locked to at creation
     * time. NULL means "base/default currency" (see lockedColumnsFor()).
     *
     * @return array<string, mixed>
     */
    public function resolveLocked(?int $currencyId): array
    {
        if ($currencyId === null) {
            return $this->currencies->default();
        }

        return $this->currencies->find($currencyId) ?? $this->currencies->default();
    }

    /**
     * The live FX rate to use for a currency, with one rule enforced above
     * whatever the row happens to say: **the base currency's rate is 1.0 by
     * definition**.
     *
     * The `currencies` table has no constraint tying the default row's
     * exchange_rate to 1, so an install that seeded USD=1/NGN=1490 and later
     * promoted NGN to default keeps NGN's 1490. Every "convert from base"
     * multiplication then inflates a base-currency amount by 1490 — the
     * ₦7,501.50 invoice charged as ₦11,177,235 at the gateway. Reading the
     * rate through here makes that data state harmless; setDefault() and
     * migration 0140 repair the stored value itself.
     *
     * @param array<string, mixed> $currency
     */
    public function rateFor(array $currency): float
    {
        $default = $this->currencies->default();
        $isDefault = (isset($currency['id']) && (int) $currency['id'] === (int) $default['id'])
            || (isset($currency['code']) && strtoupper((string) $currency['code']) === strtoupper((string) $default['code']));

        if ($isDefault) {
            return 1.0;
        }

        $rate = (float) ($currency['exchange_rate'] ?? 1.0);

        return $rate > 0 ? $rate : 1.0;
    }

    /** Same rule as rateFor(), keyed by ISO code. Unknown codes fall back to 1.0. */
    public function rateForCode(string $code): float
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return 1.0;
        }

        $currency = $this->currencies->findByCode($code);

        return $currency !== null ? $this->rateFor($currency) : 1.0;
    }

    /** The ISO code a document denominated in $currencyId is expressed in (NULL = base). */
    public function codeFor(?int $currencyId): string
    {
        return strtoupper((string) ($this->resolveLocked($currencyId)['code'] ?? 'USD'));
    }

    /**
     * Re-expresses an amount that is already in $fromCode into $toCode.
     *
     * Rates read "units of this currency per 1 base unit", so going between
     * two non-base currencies is toRate/fromRate. Identical codes are a
     * no-op — the case that matters most, since charging an NGN invoice
     * through an NGN gateway must never touch the figure at all.
     */
    public function crossConvert(float $amount, string $fromCode, string $toCode): float
    {
        $from = strtoupper(trim($fromCode));
        $to = strtoupper(trim($toCode));

        if ($from === '' || $to === '' || $from === $to) {
            return round($amount, 2);
        }

        $fromRate = $this->rateForCode($from);

        return round($amount * ($this->rateForCode($to) / ($fromRate > 0 ? $fromRate : 1.0)), 2);
    }

    /** Formats a base-currency amount using a document's own locked rate (not the currency's live rate). */
    public function formatLocked(float $baseAmount, ?int $currencyId, float $lockedRate): string
    {
        $currency = $this->resolveLocked($currencyId);

        // A document locked to the *base* currency is stored in that currency
        // already; both lockColumns() and denominateFor() write 1.0 for it, so
        // any other value on such a row is corrupt data, not a conversion.
        $effectiveRate = $currencyId === null
            ? 1.0
            : ($lockedRate > 0 ? $lockedRate : 1.0);

        return ($currency['symbol'] ?? '$') . number_format(round($baseAmount * $effectiveRate, 2), 2);
    }

    /**
     * Formats a stored document amount (invoice, quote, credit note) for the
     * client who owns it.
     *
     * A document that locked a currency at creation keeps displaying in that
     * currency at that rate — a historical record must never re-price itself
     * when today's exchange rate moves.
     *
     * A document with no lock is the case this method exists for. It was
     * imported from another system, or created before currency locking, or by
     * a path that skipped it. Its stored amount is simply what the client was
     * billed, so it is shown with that client's own symbol and no conversion.
     * Falling back to the *system* default instead is what made one client's
     * list read "$7,501.50", "$29,755.95" and "₦0.00" side by side.
     *
     * @param array<string, mixed>|null $clientCurrency from resolveForClient()
     */
    public function formatDocument(float $amount, ?int $currencyId, float $lockedRate, ?array $clientCurrency = null): string
    {
        if ($currencyId !== null) {
            return $this->formatLocked($amount, $currencyId, $lockedRate);
        }

        $symbol = (string) ($clientCurrency['symbol'] ?? $this->currencies->default()['symbol'] ?? '$');

        return $symbol . number_format(round($amount, 2), 2);
    }

    public function convert(float $baseAmount, float $rate): float
    {
        $effectiveRate = $rate > 0 ? $rate : 1.0;

        return round($baseAmount * $effectiveRate, 2);
    }

    /** @param array<string, mixed> $currency */
    public function format(float $baseAmount, array $currency): string
    {
        $converted = $this->convert($baseAmount, $this->rateFor($currency));

        return ($currency['symbol'] ?? '$') . number_format($converted, 2);
    }

    /**
     * Locked columns to store on an order/invoice at creation time, for
     * background jobs with no request/session context (renewals, billable
     * items, proration) — falls back to the client's saved preference only,
     * since there's no in-session currency choice to honor there.
     *
     * @return array{currency_id: int|null, currency_rate: float}
     */
    public function lockedColumnsFor(?array $client): array
    {
        return $this->lockColumns($this->resolveForClient($client));
    }

    /**
     * Locked columns for an already-resolved currency — use this at
     * checkout (via resolveEffective()) so the order/invoice locks to
     * whatever currency the client was actually shown, not just their
     * saved profile preference.
     *
     * @param array<string, mixed> $currency
     * @return array{currency_id: int|null, currency_rate: float}
     */
    /**
     * Currency columns for an invoice whose amounts are ALREADY in the
     * client's currency — records which currency it is, without a conversion
     * rate.
     *
     * lockColumns() assumes catalog prices are held in the base currency and
     * converts on top. That is only true when the base currency is the one
     * prices were actually typed in. On an install where products are priced
     * in the local currency while a separate currency exists for it at a live
     * FX rate, locking that rate multiplies every generated invoice by it: a
     * ₦7,451.49 plan was invoiced at ₦11,177,235.
     *
     * Invoice generation therefore denominates rather than converts — the
     * amount raised equals the price on the product, which is what an admin
     * setting that price expects. Rate 1.0 means "already in this currency",
     * the same shape as every imported invoice and the manual-invoice screen.
     *
     * @param array<string, mixed>|null $client
     * @return array{currency_id: int|null, currency_rate: float}
     */
    public function denominateFor(?array $client): array
    {
        return $this->denominateColumns($this->resolveForClient($client));
    }

    /**
     * Denominated columns for an already-resolved currency — use this where
     * an in-session choice can override the client's saved preference (see
     * resolveEffective()), the same relationship lockColumns() has to
     * lockedColumnsFor().
     *
     * @param array<string, mixed> $currency
     * @return array{currency_id: int|null, currency_rate: float}
     */
    public function denominateColumns(array $currency): array
    {
        $default = $this->currencies->default();

        return [
            // NULL for the default currency, matching lockColumns()' convention
            // so display code keeps a single "NULL means base" branch.
            'currency_id' => (int) $currency['id'] === (int) $default['id'] ? null : (int) $currency['id'],
            'currency_rate' => 1.0000,
        ];
    }

    public function lockColumns(array $currency): array
    {
        $default = $this->currencies->default();

        // A default-currency order stores NULL, not the default's own id —
        // keeps every pre-R11 row (which is implicitly "default currency")
        // and every new default-currency order in the same NULL bucket, so
        // display code has one branch ("NULL or rate 1.0 => base currency")
        // instead of two equivalent representations to check.
        if ((int) $currency['id'] === (int) $default['id']) {
            return ['currency_id' => null, 'currency_rate' => 1.0000];
        }

        return ['currency_id' => (int) $currency['id'], 'currency_rate' => $this->rateFor($currency)];
    }
}
