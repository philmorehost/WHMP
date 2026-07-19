<?php

declare(strict_types=1);

// Valid-user max-fails -> account lock (blueprint §5), independent of any
// IP-level action — a locked account stays locked regardless of which IP
// is used to try it.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS security_account_locks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            locked_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            reason VARCHAR(255) NULL,
            INDEX idx_admin (admin_id),
            CONSTRAINT fk_security_account_locks_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
