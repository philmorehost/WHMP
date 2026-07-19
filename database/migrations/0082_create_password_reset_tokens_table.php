<?php

declare(strict_types=1);

// R14 — Password reset (blueprint §4.1 "Password reset", never built through
// R0-R13). Shared table for both admins and clients rather than two
// near-identical tables — account_type + account_id disambiguates. Only the
// token's SHA-256 hash is stored (same reasoning as RecoveryCodes: a DB leak
// alone must not yield a usable reset link), and issuing a new token deletes
// any prior ones for that account so only the most recent reset link works.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_type ENUM('admin', 'client') NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_account (account_type, account_id),
            INDEX idx_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
