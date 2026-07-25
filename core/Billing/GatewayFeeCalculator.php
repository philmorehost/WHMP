<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * Works out what a gateway will charge in processing fees for a given
 * transaction, from each provider's published pricing.
 *
 * Deliberately pure: it takes an amount and a tier and returns a number, with
 * no database, config or request state involved, so the published schedules
 * can be unit-tested directly against the providers' own worked examples.
 *
 * IMPORTANT — the fixed components below (N100, N150, N250) and the caps are
 * denominated in Naira, because that is how all four providers publish them.
 * The amount passed in must therefore be in the GATEWAY's currency, and fees
 * only apply meaningfully when that currency is NGN; see feeFor()'s guard.
 */
final class GatewayFeeCalculator
{
    public const TIER_LOCAL = 'local';
    public const TIER_INTERNATIONAL = 'international';

    /**
     * Published pricing per gateway and tier.
     *
     *   percent          — percentage component
     *   percent_cap      — ceiling on the percentage component alone
     *   fixed            — flat component added to the percentage
     *   fixed_waived_below — flat component is dropped under this amount
     *   total_cap        — ceiling on the whole fee
     *
     * Sources: paystack.com/pricing, flutterwave.com/ng/pricing,
     * merchant.payhub.com.ng/pricing.php, plisio.net/pricing.
     *
     * PayHub's local pricing is self-contradictory as published — it states
     * "1.6% + N150.00" alongside "Fee capped at N100.00", and a N150 flat fee
     * cannot sit beneath a N100 ceiling. It is encoded here as a cap on the
     * percentage component, the only reading under which both figures hold.
     *
     * @var array<string, array<string, array<string, float|null>>>
     */
    private const SCHEDULES = [
        'paystack' => [
            self::TIER_LOCAL => [
                'percent' => 1.5,
                'percent_cap' => null,
                'fixed' => 100.0,
                'fixed_waived_below' => 2500.0,
                'total_cap' => 2000.0,
            ],
            self::TIER_INTERNATIONAL => [
                'percent' => 3.9,
                'percent_cap' => null,
                'fixed' => 100.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
        ],
        'flutterwave' => [
            // 1.4% transaction + 0.6% platform, published as a flat 2%.
            self::TIER_LOCAL => [
                'percent' => 2.0,
                'percent_cap' => null,
                'fixed' => 0.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
            self::TIER_INTERNATIONAL => [
                'percent' => 4.8,
                'percent_cap' => null,
                'fixed' => 0.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
        ],
        'payhub' => [
            self::TIER_LOCAL => [
                'percent' => 1.6,
                'percent_cap' => 100.0,
                'fixed' => 150.0,
                'fixed_waived_below' => 2500.0,
                'total_cap' => null,
            ],
            self::TIER_INTERNATIONAL => [
                'percent' => 4.5,
                'percent_cap' => null,
                'fixed' => 250.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
        ],
        'plisio' => [
            // Crypto settlement — one rate regardless of payer location.
            self::TIER_LOCAL => [
                'percent' => 0.5,
                'percent_cap' => null,
                'fixed' => 0.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
            self::TIER_INTERNATIONAL => [
                'percent' => 0.5,
                'percent_cap' => null,
                'fixed' => 0.0,
                'fixed_waived_below' => null,
                'total_cap' => null,
            ],
        ],
    ];

    /** Gateways with no published schedule (manual bank transfer) cost nothing to accept. */
    public function calculate(string $gatewaySlug, float $amount, string $tier): float
    {
        $schedule = self::SCHEDULES[strtolower($gatewaySlug)][$tier] ?? null;

        if ($schedule === null || $amount <= 0) {
            return 0.0;
        }

        $percentPart = $amount * ((float) $schedule['percent'] / 100);

        if ($schedule['percent_cap'] !== null) {
            $percentPart = min($percentPart, (float) $schedule['percent_cap']);
        }

        $fixedPart = (float) $schedule['fixed'];

        if ($schedule['fixed_waived_below'] !== null && $amount < (float) $schedule['fixed_waived_below']) {
            $fixedPart = 0.0;
        }

        $fee = $percentPart + $fixedPart;

        if ($schedule['total_cap'] !== null) {
            $fee = min($fee, (float) $schedule['total_cap']);
        }

        return round($fee, 2);
    }

    /**
     * Which published tier applies to a client. Providers price by where the
     * card is issued, which is not knowable before the payer enters it, so
     * this uses the country on the client's profile as the best available
     * proxy — a Nigerian client paying with a foreign card is billed the local
     * tier and the difference comes out of the merchant's margin.
     *
     * @param array<string, mixed>|null $client
     */
    public function tierForClient(?array $client): string
    {
        $country = strtoupper(trim((string) ($client['country'] ?? '')));

        // An unset country means an older or incomplete profile; treat it as
        // local rather than surprising a Nigerian client with the much higher
        // international rate.
        if ($country === '' || $country === 'NG' || $country === 'NIGERIA') {
            return self::TIER_LOCAL;
        }

        return self::TIER_INTERNATIONAL;
    }

    /**
     * The fee to add to a charge, honouring the per-gateway admin setting and
     * the Naira-denominated nature of the published schedules.
     *
     * @param array<string, mixed> $gatewayConfig
     * @param array<string, mixed>|null $client
     */
    public function feeFor(string $gatewaySlug, float $gatewayAmount, string $gatewayCurrency, array $gatewayConfig, ?array $client): float
    {
        // Off unless an admin has explicitly turned fee pass-through on for
        // this gateway, so enabling the feature never silently re-prices every
        // existing gateway at once.
        if (empty($gatewayConfig['pass_fee_to_client'])) {
            return 0.0;
        }

        // Every published schedule is quoted in Naira. Applying a N100 flat
        // component to a charge denominated in anything else would be wrong by
        // whatever the exchange rate happens to be, so decline rather than
        // guess.
        if (strtoupper($gatewayCurrency) !== 'NGN') {
            return 0.0;
        }

        return $this->calculate($gatewaySlug, $gatewayAmount, $this->tierForClient($client));
    }
}
