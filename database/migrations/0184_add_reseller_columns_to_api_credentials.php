<?php

declare(strict_types=1);

use CodeVault\Database;

// Reseller program.
//
// Until now every api_credentials row was staff-owned: an admin minted it from
// /admin/api-credentials, it held scopes, and it was a key for the whole
// install. A reseller key is different in three ways, and each needs a column:
//
//   client_id       marks the credential as belonging to a CLIENT (the
//                   reseller) rather than to staff. NULL keeps every existing
//                   row exactly as it was — the admin screen and any scopes it
//                   already holds keep working untouched. ON DELETE CASCADE
//                   because the key is part of that client's account: deleting
//                   the client must not leave a live key behind pointing at
//                   nobody.
//
//   reseller_domain the site the reseller will sell from. This is the whole
//                   point of the activation gate: a key with no declared
//                   storefront is a key nobody can be held to, so it stays
//                   inert until one is supplied.
//
//   activated_at    when the key was switched on. `active` already exists and
//                   is what the authenticator checks; this column records WHEN,
//                   so the admin list can show it and support can tell "never
//                   activated" from "switched off later".
//
// A reseller credential is therefore created with active = 0 and is only
// activated by ResellerCredentialService::activate(), which requires a valid
// domain. Nothing else flips it on.
//
// The two discount percentages live in `settings` (like every other admin
// tunable) rather than in a table of their own, and default to 0 — so this
// migration changes no price anywhere until an admin sets them.
//
// Guarded through INFORMATION_SCHEMA (see 0179/0182/0183) so this is portable
// and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $columnExists = static function (Database $db, string $table, string $column): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                ) !== null;
            };

            $constraintExists = static function (Database $db, string $table, string $constraint): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [$table, $constraint]
                ) !== null;
            };

            $indexExists = static function (Database $db, string $table, string $index): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $index]
                ) !== null;
            };

            if (!$columnExists($db, 'api_credentials', 'client_id')) {
                $db->statement('ALTER TABLE api_credentials ADD COLUMN client_id INT UNSIGNED NULL AFTER id');
            }

            if (!$columnExists($db, 'api_credentials', 'reseller_domain')) {
                $db->statement('ALTER TABLE api_credentials ADD COLUMN reseller_domain VARCHAR(255) NULL AFTER scopes');
            }

            if (!$columnExists($db, 'api_credentials', 'activated_at')) {
                $db->statement('ALTER TABLE api_credentials ADD COLUMN activated_at DATETIME NULL AFTER active');
            }

            if (!$indexExists($db, 'api_credentials', 'idx_client')) {
                $db->statement('ALTER TABLE api_credentials ADD INDEX idx_client (client_id)');
            }

            if (!$constraintExists($db, 'api_credentials', 'fk_api_credentials_client')) {
                $db->statement('ALTER TABLE api_credentials ADD CONSTRAINT fk_api_credentials_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE');
            }
        },
        // Percentages the reseller pays off list price. '0' is the pre-existing
        // behaviour: the program exists, resellers get list price until an admin
        // decides otherwise.
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.discount_services', '0')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.discount_domains', '0')",
    ],
];
