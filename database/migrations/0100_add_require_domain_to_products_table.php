<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE products ADD COLUMN require_domain TINYINT(1) NOT NULL DEFAULT 0 AFTER is_upsell',
    ],
];
