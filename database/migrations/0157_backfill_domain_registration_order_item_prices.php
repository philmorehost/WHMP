<?php

declare(strict_types=1);

// A standalone domain order rides on the hidden $0 "Domain Registration"
// carrier product, so its order item was written with unit_price 0 even
// though the client was charged the domain's own price (that amount lives on
// the domains row and in the order total). The admin order page therefore
// rendered the line as 0.00 and the product revenue report silently omitted
// every domain registration.
//
// CheckoutService now writes the domain price onto the carrier order item at
// checkout; this backfills orders created before that fix. Scope is kept to
// the unambiguous case: an order with exactly one domain row (so the amount
// to copy is unambiguous) whose carrier order item is still a zero charge.

return [
    'up' => [
        <<<'SQL'
        UPDATE order_items oi
        JOIN (
            SELECT d.order_id, d.amount
            FROM domains d
            JOIN (
                SELECT order_id
                FROM domains
                GROUP BY order_id
                HAVING COUNT(*) = 1
            ) single ON single.order_id = d.order_id
            WHERE d.amount > 0
        ) dom ON dom.order_id = oi.order_id
        JOIN products p ON p.id = oi.product_id
            AND p.name = 'Domain Registration'
            AND p.status = 'hidden'
        SET oi.unit_price = dom.amount
        WHERE oi.unit_price = 0
          AND oi.setup_fee = 0
        SQL,
    ],
];
