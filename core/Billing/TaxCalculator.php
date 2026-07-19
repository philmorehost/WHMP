<?php

declare(strict_types=1);

namespace CodeVault\Billing;

final class TaxCalculator
{
    public function __construct(
        private readonly TaxRuleRepository $taxRules,
        private readonly VatNumberValidator $vatValidator,
        private readonly TaxSettings $taxSettings
    ) {
    }

    /**
     * @param array<string, mixed> $client
     * @return array{rate: float, name: string, amount: float, reverseCharge: bool}
     */
    public function calculate(array $client, float $taxableAmount): array
    {
        if ((bool) ($client['tax_exempt'] ?? false)) {
            return ['rate' => 0.0, 'name' => 'Tax', 'amount' => 0.0, 'reverseCharge' => false];
        }

        if ($this->qualifiesForReverseCharge($client)) {
            $clientCountry = strtoupper((string) $client['country']);

            return [
                'rate' => 0.0,
                'name' => "Reverse Charge (VAT {$clientCountry})",
                'amount' => 0.0,
                'reverseCharge' => true,
            ];
        }

        $rule = $this->taxRules->resolve($client['country'] ?? null, $client['state'] ?? null);

        if ($rule === null) {
            return ['rate' => 0.0, 'name' => 'Tax', 'amount' => 0.0, 'reverseCharge' => false];
        }

        $rate = (float) $rule['rate'];

        return [
            'rate' => $rate,
            'name' => $rule['name'],
            'amount' => round($taxableAmount * ($rate / 100), 2),
            'reverseCharge' => false,
        ];
    }

    /**
     * EU-VAT-style B2B reverse charge: the client is in a different
     * country than this business (blueprint's "cross-border") and
     * supplied a structurally valid VAT number for their own country —
     * live VIES verification is a separate, on-demand action
     * (VatLookupService), not a checkout-time requirement.
     *
     * @param array<string, mixed> $client
     */
    private function qualifiesForReverseCharge(array $client): bool
    {
        $sellerCountry = $this->taxSettings->sellerCountryCode();
        $clientCountry = $client['country'] ?? null;
        $vatNumber = trim((string) ($client['vat_number'] ?? ''));

        if ($sellerCountry === null || $clientCountry === null || $vatNumber === '') {
            return false;
        }

        if (strtoupper((string) $clientCountry) === $sellerCountry) {
            return false;
        }

        return $this->vatValidator->isValidFormat((string) $clientCountry, $vatNumber);
    }
}
