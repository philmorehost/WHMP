<?php

declare(strict_types=1);

// Resellers managing their own customers, and signing in to a customer's account
// on someone else's behalf (a reseller for its customer, an admin for anyone).
//
// services.suspended_by_reseller_id — WHO MAY LIFT A SUSPENSION
//
// A reseller may suspend its customer's service and lift that suspension again. It
// may NOT lift a suspension it did not make: one we made for an unpaid invoice, for
// abuse, or by hand. We collect the customer's payments, so letting a store unsuspend
// an overdue service would let it hand out hosting nobody has paid for.
//
// So a reseller's suspension is marked with the store's id, and every other status
// change clears the mark (ServiceRepository::setStatus). The mark therefore means
// exactly "suspended by this store, and nobody has touched it since". No foreign key:
// a deleted store leaves a stale id that matches no store, which is harmless, and an
// FK on `services` would rebuild the busiest table on every live site.
//
// client_impersonation_tokens — SIGNING IN ON ANOTHER SITE
//
// Session cookies are per-host. A store's customer lives on the store's website, but
// the reseller (and the admin) are signed in on the platform's. The way across is a
// one-time ticket: issued on the platform to an authenticated reseller/admin, redeemed
// once, within two minutes, on the one site it names. Only the SHA-256 of the token
// is stored, so a database read cannot be replayed into a session.

return [
    'up' => [
        static function (\CodeVault\Database $db): void {
            $exists = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'suspended_by_reseller_id'"
            );

            if ($exists === null) {
                $db->statement('ALTER TABLE services ADD COLUMN suspended_by_reseller_id INT UNSIGNED NULL AFTER suspension_reason');
            }

            $indexed = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND INDEX_NAME = 'idx_services_reseller_hold'"
            );

            if ($indexed === null) {
                $db->statement('ALTER TABLE services ADD INDEX idx_services_reseller_hold (suspended_by_reseller_id)');
            }
        },

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_impersonation_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            actor_type ENUM('admin','reseller') NOT NULL,
            actor_id INT UNSIGNED NOT NULL,
            actor_label VARCHAR(191) NOT NULL,
            site_reseller_id INT UNSIGNED NULL,
            return_url VARCHAR(500) NOT NULL,
            ip_address VARCHAR(45) NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_client_impersonation_token (token_hash),
            INDEX idx_client_impersonation_client (client_id, created_at),
            CONSTRAINT fk_client_impersonation_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
