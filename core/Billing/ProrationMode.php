<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * The proration-mode matrix called out explicitly in the blueprint (§4.4
 * Upgrade/Downgrade engine): none / full-credit / prorata-days-remaining.
 */
final class ProrationMode
{
    public const NONE = 'none';
    public const FULL_CREDIT = 'full_credit';
    public const PRORATA = 'prorata';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::NONE => 'None — switch now, bill new price at next renewal',
            self::FULL_CREDIT => 'Full Credit — credit all unused time, invoice a full new cycle now',
            self::PRORATA => 'Prorata — charge/credit only the difference for days remaining',
        ];
    }
}
