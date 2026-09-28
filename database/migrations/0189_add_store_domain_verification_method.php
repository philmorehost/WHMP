<?php

declare(strict_types=1);

// How a store's custom domain came to be verified.
//
// `domain_verified_at` already answers WHEN. This column answers HOW, which is
// the question support actually gets asked:
//
//   'txt'    a TXT record at _codevault-verify.{domain} proved control
//   'cname'  the domain itself points at the platform host
//   'manual' an admin forced it, for a provider whose DNS we cannot query
//   NULL     verified before this column existed, or never verified
//
// Without it, an admin override is indistinguishable from a real DNS proof once
// the activity log scrolls away — and an override is exactly the thing that
// should stay visible on the store itself.
//
// Guarded through INFORMATION_SCHEMA (see 0179/0182/0183/0184/0188) so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $exists = $db->select(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['resellers', 'domain_verification_method']
            );

            if ($exists === []) {
                $db->statement('ALTER TABLE resellers ADD COLUMN domain_verification_method VARCHAR(16) NULL AFTER domain_verification_token');
            }
        },
    ],
];
