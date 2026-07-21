<?php

declare(strict_types=1);

return [
    'up' => [
        "INSERT INTO payment_gateways (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('paypal', 'PayPal', NULL, 0, 5, NOW(), NOW())",
    ],
];
