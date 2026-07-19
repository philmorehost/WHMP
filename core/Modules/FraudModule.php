<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Fraud-scoring plugins (blueprint §4.4 fraud engine + §3 — added so the
 * fraud engine has a module contract like every other engine). MaxMind
 * minFraud, custom rule engines, or AI-triage providers all implement this;
 * the Pending Orders queue calls every active FraudModule and combines
 * scores into a hold/pass decision.
 */
interface FraudModule extends Module
{
    /**
     * @param array<string, mixed> $order order + client + payment context
     * @return array{score: float, hold: bool, reasons: array<int, string>}
     */
    public function score(array $order): array;
}
