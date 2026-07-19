<?php

declare(strict_types=1);

return [
    'up' => [
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('payhub', 'Payhub', NULL, 0, 3, NOW(), NOW())",
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('plisio', 'Plisio Crypto', NULL, 0, 4, NOW(), NOW())",
    ],
];
