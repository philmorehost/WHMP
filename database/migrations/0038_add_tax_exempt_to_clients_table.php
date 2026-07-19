<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN tax_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER country',
    ],
];
