<?php

declare(strict_types=1);

// Same purpose as 0048's Upperlink/ConnectReseller seed — ResellerClub
// and Namecheap are registered as ModuleManager code-level modules but
// need their own `registrars` row to be configurable/enabled from the
// admin UI at all. Disabled by default since neither has credentials
// configured yet.

return [
    'up' => [
        "INSERT INTO registrars (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('resellerclub', 'ResellerClub', NULL, 0, 3, NOW(), NOW())",
        "INSERT INTO registrars (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('namecheap', 'Namecheap', NULL, 0, 4, NOW(), NOW())",
    ],
];
