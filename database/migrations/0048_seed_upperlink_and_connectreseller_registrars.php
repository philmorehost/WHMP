<?php

declare(strict_types=1);

// 0047 only seeded 'local' — Upperlink and ConnectReseller are registered
// as ModuleManager code-level modules but need their own `registrars` row
// to be configurable/enabled from the admin UI at all. Disabled by
// default since neither has credentials configured yet.

return [
    'up' => [
        "INSERT INTO registrars (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('upperlink', 'Upperlink', NULL, 0, 1, NOW(), NOW())",
        "INSERT INTO registrars (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('connectreseller', 'ConnectReseller', NULL, 0, 2, NOW(), NOW())",
    ],
];
