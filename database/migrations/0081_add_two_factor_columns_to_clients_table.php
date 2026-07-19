<?php

declare(strict_types=1);

// Client-area equivalent of 0080 — blueprint §4.3 client pages list names
// "Security (2FA)" explicitly, never built through R0-R12.

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN two_factor_secret VARCHAR(32) NULL AFTER password_hash',
        'ALTER TABLE clients ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret',
        'ALTER TABLE clients ADD COLUMN two_factor_recovery_codes TEXT NULL AFTER two_factor_enabled',
    ],
];
