<?php

declare(strict_types=1);

use CodeVault\Database;

// Per-reseller gateway visibility: lets the admin switch a payment gateway
// off for clients of RESELLER stores only, while it stays available on the
// platform's own site. A reseller client is any clients row with
// reseller_id set — the same PLATFORM_ONLY distinction every client query
// already draws. Default 1 so existing gateways behave exactly as before
// until an admin changes it.
//
// Enforcement lives where the money moves (PaymentCallbackController) and
// where the choices are listed (invoice page, username-changer panel);
// the admin toggle is Admin → Payment Gateways.
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['payment_gateways', 'reseller_enabled']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE payment_gateways ADD COLUMN reseller_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_enabled');
        },
    ],
];
