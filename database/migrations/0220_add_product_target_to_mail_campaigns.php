<?php

declare(strict_types=1);

use CodeVault\Database;

// Lets a marketing campaign target clients by the products they hold — the
// WHMCS-style "email everyone who has product X" audience. The selected
// product ids are stored as a comma-separated list (canonicalised by the
// controller: unique, sorted, ints only) in a single TEXT column, the same
// way external_emails stores the pasted address list. NULL/empty means the
// campaign does not use this target type.
//
// Recipients are resolved at send time from the services table (clients with
// an active or suspended service of any selected product), so the audience
// tracks who currently holds the product — see
// ClientRepository::activeForProducts() and
// MailCampaignService::resolveRecipients().
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['mail_campaigns', 'product_ids']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE mail_campaigns ADD COLUMN product_ids TEXT NULL AFTER only_inactive');
        },
    ],
];
