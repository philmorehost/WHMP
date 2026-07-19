<?php

declare(strict_types=1);

// Manual/Bank Transfer is always available out of the box — no API keys
// needed, so R4's checkout flow has a real, immediately testable payment
// path (blueprint §10: gateway choice for R4 was left open; a real
// third-party gateway is a later addition, not a blocker).

return [
    'up' => [
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('manual', 'Bank Transfer / Manual Payment', NULL, 1, 0, NOW(), NOW())",
    ],
];
