<?php

declare(strict_types=1);

// Manual cryptocurrency payments (USDT TRC-20) — the human-reviewed
// alternative to the Plisio gateway. The client pays the quoted USD amount
// to the admin's TRC-20 wallet, uploads proof (screenshot / TXID), and an
// admin approves before the invoice is marked paid (deposit invoices then
// become wallet credit through the ordinary payment pipeline).
//
// One row per payment attempt: the USD figure and its rate are LOCKED the
// moment the client submits (never recomputed — an approval must match what
// the client actually sent), together with the wallet address the client was
// shown. Status flow: pending → approved | rejected (a rejection invites a
// new submission; nothing is ever deleted, so an audit trail remains).
//
// Re-runnable via CREATE TABLE IF NOT EXISTS (the migrator heals on boot),
// and the gateway row is seeded disabled — an admin enables it and pastes
// their wallet address after configuring (Admin → Payment Gateways).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS manual_crypto_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            reference VARCHAR(64) NOT NULL,
            amount_usd DECIMAL(14, 2) NOT NULL DEFAULT 0,
            usd_currency_id INT UNSIGNED DEFAULT NULL,
            usd_rate DECIMAL(16, 8) NOT NULL DEFAULT 1,
            wallet_address VARCHAR(128) NOT NULL,
            txid VARCHAR(191) DEFAULT NULL,
            proof_path VARCHAR(255) DEFAULT NULL,
            proof_name VARCHAR(255) DEFAULT NULL,
            proof_mime VARCHAR(128) DEFAULT NULL,
            proof_size INT UNSIGNED DEFAULT NULL,
            note TEXT,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            admin_id INT UNSIGNED DEFAULT NULL,
            admin_note TEXT,
            reviewed_at DATETIME DEFAULT NULL,
            transaction_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_manual_crypto_reference (reference),
            KEY idx_manual_crypto_invoice (invoice_id),
            KEY idx_manual_crypto_client (client_id),
            KEY idx_manual_crypto_status (status)
        )
        SQL,
        static function (\CodeVault\Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM payment_gateways WHERE slug = ?',
                ['crypto_manual']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement(
                "INSERT INTO payment_gateways (slug, name, config, is_enabled, reseller_enabled, sort_order, created_at, updated_at) VALUES ('crypto_manual', 'Crypto — USDT (TRC-20) · Manual review', NULL, 0, 0, 5, NOW(), NOW())"
            );
        },
    ],
];
