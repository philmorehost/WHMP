<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN security_pin_hash VARCHAR(255) NULL AFTER password_hash',
        'ALTER TABLE admins ADD COLUMN security_pin_hash VARCHAR(255) NULL AFTER password_hash',
    ],
    'down' => [
        'ALTER TABLE clients DROP COLUMN security_pin_hash',
        'ALTER TABLE admins DROP COLUMN security_pin_hash',
    ],
];
