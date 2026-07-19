<?php

declare(strict_types=1);

// Every admin gets a role. A "Full Administrator" super-admin role is
// seeded and backfilled onto any admin created before this migration
// (the installer's bootstrap account) so nobody is left roleless.

return [
    'up' => [
        'ALTER TABLE admins ADD COLUMN role_id INT UNSIGNED NULL AFTER display_name',
        "INSERT INTO roles (name, is_super_admin, created_at, updated_at) VALUES ('Full Administrator', 1, NOW(), NOW())",
        'UPDATE admins SET role_id = (SELECT id FROM roles WHERE name = \'Full Administrator\' LIMIT 1) WHERE role_id IS NULL',
        'ALTER TABLE admins ADD CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL',
    ],
];
