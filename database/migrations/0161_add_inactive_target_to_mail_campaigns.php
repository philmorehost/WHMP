<?php

declare(strict_types=1);

use CodeVault\Database;

// Lets a marketing campaign target active accounts that hold no active
// product or domain — the "inactive buyers" audience for re-engagement
// campaigns (someone with an account but nothing live to renew).
//
// This is a boolean flag on the campaign rather than a new enum value in
// target columns: the existing targeting model stores *where* to look
// (client_group_id / client_id / external_emails, all NULL meaning "all
// active clients"), and this flag narrows that same audience to clients
// with no service and no domain in an 'active' state.
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['mail_campaigns', 'only_inactive']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE mail_campaigns ADD COLUMN only_inactive TINYINT(1) NOT NULL DEFAULT 0 AFTER external_emails');
        },
    ],
];
