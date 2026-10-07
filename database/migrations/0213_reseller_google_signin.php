<?php

declare(strict_types=1);

// "Sign in with Google" on reseller stores.
//
// Each store uses its OWN Google app (the reseller's client ID and secret), so its
// customers come back to the store's own address and see the reseller's brand on
// Google's consent screen — never the platform's (see CodeVault\Clients\GoogleSignIn).
//
//   google_enabled        1 = show the button on this store. Off for every store until
//                         its owner switches it on in the reseller panel.
//   google_client_id      the store's Google OAuth client ID.
//   google_client_secret  the matching secret, encrypted with APP_KEY (SecretBox). TEXT
//                         because the encrypted form is longer than the secret, and so
//                         the resellers row stays well inside MySQL's row-size limit.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            foreach ([
                'google_enabled' => 'google_enabled TINYINT(1) NOT NULL DEFAULT 0',
                'google_client_id' => 'google_client_id VARCHAR(191) NULL',
                'google_client_secret' => 'google_client_secret TEXT NULL',
            ] as $column => $definition) {
                $exists = (string) ($db->selectOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resellers' AND COLUMN_NAME = ?",
                    [$column]
                )['COLUMN_NAME'] ?? '') !== '';

                if (!$exists) {
                    $db->statement('ALTER TABLE resellers ADD COLUMN ' . $definition);
                }
            }
        },
    ],
];
