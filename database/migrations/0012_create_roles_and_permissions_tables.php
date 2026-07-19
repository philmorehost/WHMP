<?php

declare(strict_types=1);

// Staff Management permission matrix (blueprint §4.3). `permissions` is a
// fixed catalog of permission keys (see CodeVault\Staff\PermissionRegistry,
// the code-level source of truth — mirrors how HookPoints works for hooks).
// `role_permissions` is the many-to-many grant table; a role with
// is_super_admin = 1 bypasses the grant table entirely (has everything).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT UNSIGNED NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            PRIMARY KEY (role_id, permission_key),
            CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
