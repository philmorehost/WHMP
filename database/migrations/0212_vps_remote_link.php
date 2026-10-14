<?php

declare(strict_types=1);

// Client self-service for VPSes that were bought and set up by hand.
//
// services.remote_id: the provider's own id for the machine behind a service
// (InterServer: the vps_uuid from GET /vps). Services ordered through the API can
// still be found by hostname, but a VPS bought by hand on the provider's site and
// then entered into WHMP rarely has a hostname that matches the provider's, so the
// admin links it explicitly from the service page. NULL means "not linked; match by
// hostname or IP as before".
//
// servers.account_secret: the provider ACCOUNT password, encrypted with APP_KEY
// (SecretBox). InterServer re-checks it on every OS reinstall and backup restore,
// on top of the API key, because those two calls wipe the disk. With it stored,
// clients can run both themselves. Without it, both still go to a support ticket.

return [
    'up' => [
        static function (\CodeVault\Database $db): void {
            $has = static fn (string $table, string $name): bool => $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $name]
            ) !== null;

            if (!$has('services', 'remote_id')) {
                $db->statement('ALTER TABLE services ADD COLUMN remote_id VARCHAR(64) NULL AFTER username');
            }

            if (!$has('servers', 'account_secret')) {
                $db->statement('ALTER TABLE servers ADD COLUMN account_secret TEXT NULL AFTER api_token');
            }
        },
    ],
];
