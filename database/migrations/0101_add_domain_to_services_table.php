<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE services ADD COLUMN domain VARCHAR(255) NULL AFTER amount',
    ],
];
