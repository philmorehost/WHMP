<?php

declare(strict_types=1);

use CodeVault\Database;

// Records the requester's IP on each registration-OTP row so the public
// (no-login) OTP issue/resend endpoints can be throttled per IP. Without a
// cap, a scripted caller can fan one OTP email per request out to an
// unlimited number of arbitrary addresses through the app's own mail
// transport — bulk email with no admin login.
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['client_registration_otps', 'ip_address']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE client_registration_otps ADD COLUMN ip_address VARCHAR(45) NULL AFTER attempts');
            $db->statement('ALTER TABLE client_registration_otps ADD INDEX idx_otps_ip (ip_address)');
        },
    ],
];
