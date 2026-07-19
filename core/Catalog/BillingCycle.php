<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

/**
 * The shared billing-cycle enum used by product pricing, configurable
 * option pricing, order items, and (later) the recurring billing engine.
 * Mirrors the MySQL ENUM values exactly — keep these in sync.
 */
final class BillingCycle
{
    public const ONE_TIME = 'one_time';
    public const MONTHLY = 'monthly';
    public const QUARTERLY = 'quarterly';
    public const SEMI_ANNUALLY = 'semi_annually';
    public const ANNUALLY = 'annually';
    public const BIENNIALLY = 'biennially';
    public const TRIENNIALLY = 'triennially';

    /** @return array<string, string> cycle key => display label */
    public static function labels(): array
    {
        return [
            self::ONE_TIME => 'One-Time',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::SEMI_ANNUALLY => 'Semi-Annually',
            self::ANNUALLY => 'Annually',
            self::BIENNIALLY => 'Biennially',
            self::TRIENNIALLY => 'Triennially',
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }
}
