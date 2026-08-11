<?php

declare(strict_types=1);

use CodeVault\Database;

// Cart abandonment recovery (blueprint §4.2 "Order Form / Cart" — the
// blueprint explicitly notes abandoned-cart emails were deliberately not
// built because carts are ephemeral session data with no persisted
// timestamp a cron sweep could find). This table is that missing half: a
// lightweight snapshot of a session's cart, refreshed on every cart
// mutation, so a cron job can find carts that have sat untouched for N
// hours and email a recovery reminder with a direct checkout link.
//
// Keyed primarily by session_id (guests and clients alike), with client_id
// captured when available and email captured either from the logged-in
// client or from a guest who opts in on the cart page. recovered_at marks
// a cart that converted so the sweep stops chasing it; reminder_sent_at
// throttles emails to one per cart (or per cooldown) rather than
// spamming a visitor every cron tick.

return [
    'up' => [
        static function (Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS abandoned_carts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(128) NOT NULL,
                    client_id INT UNSIGNED NULL,
                    email VARCHAR(190) NULL,
                    items LONGTEXT NOT NULL,
                    promo_code VARCHAR(50) NULL,
                    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    currency_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    reminder_sent_at DATETIME NULL,
                    recovered_at DATETIME NULL,
                    UNIQUE KEY idx_abandoned_session (session_id),
                    KEY idx_abandoned_client (client_id),
                    KEY idx_abandoned_stale (recovered_at, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL
            );
        },
    ],
];
