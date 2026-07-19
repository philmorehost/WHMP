<?php

declare(strict_types=1);

// R13 — 2FA (blueprint §4.3 "Staff Management ... 2FA", never built through
// R0-R12). two_factor_secret is only ever non-null once 2FA is actually
// enabled (confirmed with a real code, not just generated) — see
// AdminAccountController. Recovery codes are stored hashed, same idiom as
// password_hash, and consumed one-time.

return [
    'up' => [
        'ALTER TABLE admins ADD COLUMN two_factor_secret VARCHAR(32) NULL AFTER password_hash',
        'ALTER TABLE admins ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret',
        'ALTER TABLE admins ADD COLUMN two_factor_recovery_codes TEXT NULL AFTER two_factor_enabled',
    ],
];
