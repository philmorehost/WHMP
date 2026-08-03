<?php

declare(strict_types=1);

// Holds the one-time code sent to verify a client's email address during
// registration (blueprint: OTP required for plain email/password signup,
// skipped for Google sign-up since Google already verified that email).
// The rest of the registration form is held in the session between the
// "send code" and "verify code" steps, not here — this table only ever
// needs the email, the hashed code, and how many wrong guesses have been
// made against it.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_registration_otps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
