<?php

declare(strict_types=1);

// R12 — resolves blueprint §10's never-answered gateway-choice decision:
// real, redirect-based payment gateways for the platform's NG market.
// Seeded disabled (no API keys configured yet) — an admin fills in
// secret/public keys on the Payment Gateways screen and enables them.

return [
    'up' => [
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('paystack', 'Paystack', NULL, 0, 1, NOW(), NOW())",
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('flutterwave', 'Flutterwave', NULL, 0, 2, NOW(), NOW())",
    ],
];
