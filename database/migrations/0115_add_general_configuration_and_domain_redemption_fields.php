<?php

declare(strict_types=1);

return [
    'up' => [
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('billing.late_fee_percentage', '5.00')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('billing.new_order_due_days', '7')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('checkout.allow_notes', '1')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('system.maintenance_mode', '0')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('billing.min_credit_balance', '0.00')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('affiliates.min_payout', '50.00')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('support.ticket_rating_enabled', '1')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('cpanel.random_usernames', '1')",
    ],
];