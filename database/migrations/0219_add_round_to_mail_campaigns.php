<?php

declare(strict_types=1);

use CodeVault\Database;

// Send rounds for mail campaigns, which is what makes "resend" honest.
//
// Resending a sent campaign appends a fresh set of mail_campaign_recipients
// rows rather than overwriting the old ones — who received an earlier send,
// and who opened it, is history worth keeping. The `round` counter on the
// campaign tells the UI which rows belong to the latest send, so progress
// and the "Sent / Opened" counts describe the current round instead of
// silently doubling once a campaign has been resent (round 1's rows stay
// visible in the recipients table, labelled with their round).
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0161), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['mail_campaigns', 'round']
            );

            if ($exists === null) {
                $db->statement('ALTER TABLE mail_campaigns ADD COLUMN round INT UNSIGNED NOT NULL DEFAULT 1 AFTER status');
            }
        },

        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['mail_campaign_recipients', 'round']
            );

            if ($exists === null) {
                $db->statement('ALTER TABLE mail_campaign_recipients ADD COLUMN round INT UNSIGNED NOT NULL DEFAULT 1 AFTER campaign_id');
            }
        },
    ],
];
