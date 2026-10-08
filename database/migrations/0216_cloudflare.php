<?php

declare(strict_types=1);

// Cloudflare add-on (docs/CLOUDFLARE_ADDON_PLAN.md) — Free plan only, Provider mode:
// every zone lives in the platform's own Cloudflare account, one per client domain.
//
// cloudflare_zones      one row per zone WHMP created for a service. Rows are kept
//                       after deletion (status 'deleted') so a domain's history and
//                       its BIND backup survive, and so a service that already had a
//                       zone is never re-enrolled automatically.
//   status              Cloudflare's own (initializing / pending / active / moved),
//                       plus 'deleted' once WHMP removed the zone.
//   original_name_servers  what the domain used before the client switched — the
//                       rollback target when Cloudflare is turned off again.
//   ns_switched_by_us   1 when WHMP changed the nameservers through its registrar
//                       (only ever on the client's click), so only then does WHMP
//                       put them back.
//   paused_by_us        1 when WHMP paused the zone because the service was
//                       suspended; unsuspending only unpauses zones WHMP paused.
//   delete_after        the 7-day grace period: set when Cloudflare is turned off or
//                       the service ends; the cron deletes the zone after it, and the
//                       client/admin can undo until then.
//   backup_bind         BIND export taken just before deletion.
// cloudflare_activity   audit trail: who did what to which zone.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cloudflare_zones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NULL,
            client_id INT UNSIGNED NOT NULL,
            reseller_id INT UNSIGNED NULL,
            cf_zone_id VARCHAR(32) NOT NULL,
            name VARCHAR(253) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            name_servers TEXT NULL,
            original_name_servers TEXT NULL,
            ns_switched_by_us TINYINT(1) NOT NULL DEFAULT 0,
            paused TINYINT(1) NOT NULL DEFAULT 0,
            paused_by_us TINYINT(1) NOT NULL DEFAULT 0,
            delete_after DATETIME NULL,
            delete_reason VARCHAR(40) NULL,
            backup_bind MEDIUMTEXT NULL,
            last_error VARCHAR(255) NULL,
            reminders_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            activated_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cf_zone (cf_zone_id),
            KEY idx_cf_service (service_id),
            KEY idx_cf_client (client_id),
            KEY idx_cf_status (status),
            KEY idx_cf_delete (delete_after)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cloudflare_activity (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            zone_id INT UNSIGNED NOT NULL,
            actor_type VARCHAR(10) NOT NULL,
            actor_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            summary VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_cfa_zone (zone_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
