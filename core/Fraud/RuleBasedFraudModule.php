<?php

declare(strict_types=1);

namespace CodeVault\Fraud;

use CodeVault\Modules\FraudModule;

/**
 * The always-real, no-external-dependency fraud module (same role as
 * LocalProvisioningModule/LocalRegistrarModule) — simple, explainable
 * heuristics rather than a black box, so it's genuinely testable and a
 * sane default before MaxMind minFraud or another paid signal is wired in.
 */
final class RuleBasedFraudModule implements FraudModule
{
    public function __construct(
        private readonly float $highValueThreshold = 500.0,
        private readonly int $newAccountMinutes = 30
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Rule-Based Fraud Check',
            'description' => 'Flags orders on simple, explainable heuristics: high order value and brand-new accounts.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'high_value_threshold' => ['type' => 'text', 'label' => 'High Value Threshold', 'default' => $this->highValueThreshold],
            'new_account_minutes' => ['type' => 'text', 'label' => 'New Account Window (minutes)', 'default' => $this->newAccountMinutes],
        ];
    }

    public function score(array $order): array
    {
        $score = 0.0;
        $reasons = [];

        $total = (float) ($order['total'] ?? 0);

        if ($total >= $this->highValueThreshold) {
            $score += 40.0;
            $reasons[] = sprintf('Order total $%.2f is at or above the high-value threshold ($%.2f).', $total, $this->highValueThreshold);
        }

        $accountAgeMinutes = $order['clientAccountAgeMinutes'] ?? null;

        if (is_numeric($accountAgeMinutes) && (float) $accountAgeMinutes < $this->newAccountMinutes) {
            $score += 35.0;
            $reasons[] = sprintf('Client account was created %.0f minute(s) ago (under the %d-minute window).', $accountAgeMinutes, $this->newAccountMinutes);
        }

        if ($total >= $this->highValueThreshold && is_numeric($accountAgeMinutes) && (float) $accountAgeMinutes < $this->newAccountMinutes) {
            $score += 15.0;
            $reasons[] = 'High-value order from a brand-new account is a compounding risk signal.';
        }

        return [
            'score' => min(100.0, $score),
            'hold' => $score >= 50.0,
            'reasons' => $reasons,
        ];
    }
}
