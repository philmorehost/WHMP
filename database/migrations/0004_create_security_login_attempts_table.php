<?php

declare(strict_types=1);

// Raw event log BruteGuard reads from — sliding-window failure counts
// (blueprint §5: "retained failed-login history (5/10/15/60 min)") and the
// admin live log panel both query this table.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS security_login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NULL,
            ip_address VARCHAR(45) NOT NULL,
            country_code CHAR(2) NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_ip_created (ip_address, created_at),
            INDEX idx_username_created (username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
