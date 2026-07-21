<?php

declare(strict_types=1);

// A hidden, $0 "carrier" product for the standalone domain-registration
// search page (/domains/register) — Cart::add()/CartService::priced()
// require a real product_id on every line (a domain-only line with no
// product would just get silently dropped, see CartService::priced()'s
// `if ($product === null) { continue; }`), and CheckoutService already
// adds the domain's own price on top of the line via domain_options
// independently of whatever product carries it. Status 'hidden' keeps it
// out of the storefront listing/search entirely.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO product_groups (name, description, sort_order, created_at, updated_at)
        SELECT 'System', 'Internal carrier products — not shown in the store.', 9999, NOW(), NOW()
        WHERE NOT EXISTS (SELECT 1 FROM product_groups WHERE name = 'System')
        SQL,
        <<<'SQL'
        INSERT INTO products (product_group_id, name, description, status, type, sort_order, created_at, updated_at)
        SELECT g.id, 'Domain Registration', 'Internal carrier for standalone domain registrations — not a purchasable product on its own.', 'hidden', 'other', 0, NOW(), NOW()
        FROM product_groups g
        WHERE g.name = 'System'
        AND NOT EXISTS (SELECT 1 FROM products WHERE name = 'Domain Registration' AND status = 'hidden')
        SQL,
        <<<'SQL'
        INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price)
        SELECT p.id, 'annually', 0.00, 0.00
        FROM products p
        WHERE p.name = 'Domain Registration' AND p.status = 'hidden'
        AND NOT EXISTS (SELECT 1 FROM product_pricing WHERE product_id = p.id AND billing_cycle = 'annually')
        SQL,
    ],
];
