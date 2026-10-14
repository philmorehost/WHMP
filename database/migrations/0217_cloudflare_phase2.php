<?php

declare(strict_types=1);

// Phase 2 Cloudflare state. DS removal and certificate metadata are retained
// locally so lifecycle cleanup can be safe and auditable.
return [
    'up' => [
        function (\CodeVault\Database $db): void {
            foreach ([
                'dnssec_status' => 'dnssec_status VARCHAR(20) NULL',
                'dnssec_ds' => 'dnssec_ds TEXT NULL',
                'dnssec_ds_by_us' => 'dnssec_ds_by_us TINYINT(1) NOT NULL DEFAULT 0',
                'dnssec_ds_removed' => 'dnssec_ds_removed TINYINT(1) NOT NULL DEFAULT 0',
                'ns_restore_after' => 'ns_restore_after DATETIME NULL',
                'dnssec_disable_after' => 'dnssec_disable_after DATETIME NULL',
                'origin_cert_id' => 'origin_cert_id VARCHAR(64) NULL',
                'origin_cert_expires' => 'origin_cert_expires DATETIME NULL',
            ] as $column => $definition) {
                $exists = (string) ($db->selectOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cloudflare_zones' AND COLUMN_NAME = ?",
                    [$column]
                )['COLUMN_NAME'] ?? '') !== '';

                if (!$exists) {
                    $db->statement('ALTER TABLE cloudflare_zones ADD COLUMN ' . $definition);
                }
            }
        },
    ],
];
