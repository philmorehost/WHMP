<?php

declare(strict_types=1);

// cPanel Username Changer (docs/CPANEL_USERNAME_CHANGER_PLAN.md).
//
// username_change_requests   one row per request; the state machine lives in
//                            UsernameChangeService. Fee columns are filled only when
//                            the super admin has switched payment ON:
//                              fee_amount / fee_currency_id   what the client was invoiced
//                              fee_retail                     the price the client's site
//                                                             charges (catalog currency)
//                              fee_cost                       what that site owes upstream
//                              fee_upline_cost                for a sub-reseller's customer,
//                                                             what the upline owes us
//                              fee_credited_at                atomic claim: the reseller
//                                                             margins were credited once
// username_change_events     the audit trail (actor + IP on every step).
// username_change_policies   overrides only (NULL = inherit) for a product, a store or
//                            a client. `fee` is the per-product fee override (product
//                            scope) or the store's own resale price (store scope).
// username_change_throttle   rate limits for the slow, rare actions (requests, resends,
//                            PIN tries). The live availability check uses the session
//                            instead, so typing never waits on a database write.
// username_change_server_accounts / username_change_servers
//                            a local copy of every WHM account name per server, kept
//                            fresh by cron (listaccts). This is what makes the live
//                            precheck instant: indexed lookups only, WHM is asked once
//                            more at submit time and again just before the rename.
//
// No column is ever added to `services`; one index is (services.username) so the
// uniqueness lookup is an index probe on large installs.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            reseller_id INT UNSIGNED NULL,
            server_id INT UNSIGNED NULL,
            old_username VARCHAR(16) NOT NULL,
            new_username VARCHAR(16) NOT NULL,
            reason VARCHAR(500) NULL,
            rename_db_objects TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL,
            confirm_method VARCHAR(8) NULL,
            confirm_token_hash CHAR(64) NULL,
            confirm_expires_at DATETIME NULL,
            confirm_sent_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            confirm_last_sent_at DATETIME NULL,
            pin_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            confirmed_at DATETIME NULL,
            decided_by_type VARCHAR(10) NULL,
            decided_by_id INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decline_reason VARCHAR(500) NULL,
            fee_amount DECIMAL(12,2) NULL,
            fee_currency_id INT UNSIGNED NULL,
            fee_retail DECIMAL(12,2) NULL,
            fee_cost DECIMAL(12,2) NULL,
            fee_upline_cost DECIMAL(12,2) NULL,
            invoice_id INT UNSIGNED NULL,
            paid_at DATETIME NULL,
            fee_credited_at DATETIME NULL,
            fee_reversed_at DATETIME NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            lock_token CHAR(32) NULL,
            locked_at DATETIME NULL,
            last_error TEXT NULL,
            server_response TEXT NULL,
            sync_state VARCHAR(12) NULL,
            requested_by_type VARCHAR(10) NOT NULL,
            requested_by_id INT UNSIGNED NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            UNIQUE KEY uniq_ucr_token (confirm_token_hash),
            INDEX idx_ucr_status (status, next_attempt_at),
            INDEX idx_ucr_service (service_id, status),
            INDEX idx_ucr_client (client_id),
            INDEX idx_ucr_reseller (reseller_id, status),
            INDEX idx_ucr_new (new_username, status),
            INDEX idx_ucr_invoice (invoice_id),
            CONSTRAINT fk_ucr_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            event VARCHAR(32) NOT NULL,
            actor_type VARCHAR(10) NOT NULL,
            actor_id INT UNSIGNED NULL,
            ip VARCHAR(45) NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_uce_request (request_id, id),
            INDEX idx_uce_created (created_at),
            CONSTRAINT fk_uce_request FOREIGN KEY (request_id) REFERENCES username_change_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_policies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(8) NOT NULL,
            scope_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NULL,
            max_changes SMALLINT UNSIGNED NULL,
            cooldown_days SMALLINT UNSIGNED NULL,
            approval VARCHAR(10) NULL,
            allow_db_rename TINYINT(1) NULL,
            client_mode VARCHAR(8) NULL,
            extra_changes SMALLINT UNSIGNED NULL,
            fee DECIMAL(12,2) NULL,
            note VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_ucp_scope (scope, scope_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_throttle (
            throttle_key VARCHAR(120) NOT NULL PRIMARY KEY,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            window_start DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_server_accounts (
            server_id INT UNSIGNED NOT NULL,
            username VARCHAR(32) NOT NULL,
            prefix8 VARCHAR(8) NOT NULL,
            domain VARCHAR(191) NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (server_id, username),
            INDEX idx_ucsa_username (username),
            INDEX idx_ucsa_prefix (server_id, prefix8)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS username_change_servers (
            server_id INT UNSIGNED NOT NULL PRIMARY KEY,
            db_engine VARCHAR(10) NULL,
            db_engine_override VARCHAR(10) NULL,
            account_count INT UNSIGNED NOT NULL DEFAULT 0,
            accounts_synced_at DATETIME NULL,
            last_error VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        static function (\CodeVault\Database $db): void {
            $indexed = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND INDEX_NAME = 'idx_services_username'"
            );

            if ($indexed === null) {
                $db->statement('ALTER TABLE services ADD INDEX idx_services_username (username)');
            }
        },

        static function (\CodeVault\Database $db): void {
            \CodeVault\UsernameChanger\UsernameChangeTemplates::ensure($db);
        },
    ],
];
