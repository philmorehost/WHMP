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

    /** Formats a base-currency amount using a document's own locked rate (not the currency's live rate). */
    public function formatLocked(float $baseAmount, ?int $currencyId, float $lockedRate): string
    {
        $currency = $this->resolveLocked($currencyId);

        return $currency['symbol'] . number_format(round($baseAmount * $lockedRate, 2), 2);
    }

    public function convert(float $baseAmount, float $rate): float
    {
        return round($baseAmount * $rate, 2);
    }

    /** @param array<string, mixed> $currency */
    public function format(float $baseAmount, array $currency): string
    {
        $converted = $this->convert($baseAmount, (float) $currency['exchange_rate']);

        return $currency['symbol'] . number_format($converted, 2);
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

        return ['currency_id' => (int) $currency['id'], 'currency_rate' => (float) $currency['exchange_rate']];
    }
}
