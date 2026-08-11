<?php

declare(strict_types=1);

use CodeVault\Database;

// API credentials for the external REST API (blueprint §3 — "scoped API
// credentials/roles"). The ApiAuthenticator/ApiCredential/ApiCredentialRepository
// interfaces existed since R0 but had no DB-backed implementation — the
// /api/* routes were only ever /api/ping. This table is that missing half.
//
// The secret is stored as an Argon2id hash (ApiCredential::hashSecret),
// never in the clear — matching how client/admin passwords are stored.
// Scopes is a JSON array like ["clients.read", "invoices.read"]; a value
// of ["*"] grants every scope. Deactivated credentials fail verification
// (ApiCredential::verifySecret checks active), so "revoke" is a flag flip,
// not a delete that would orphan audit history.

return [
    'up' => [
        static function (Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS api_credentials (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    label VARCHAR(120) NOT NULL,
                    api_key VARCHAR(64) NOT NULL,
                    secret_hash VARCHAR(255) NOT NULL,
                    scopes JSON NOT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    last_used_at DATETIME NULL,
                    UNIQUE KEY uq_api_key (api_key),
                    INDEX idx_active (active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );
        },
    ],
];
