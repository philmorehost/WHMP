<?php

declare(strict_types=1);

// A client's preferred display currency (blueprint §4.4 multi-currency).
// NULL = use the system default currency.

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN currency_id INT UNSIGNED NULL AFTER client_group_id',
        'ALTER TABLE clients ADD CONSTRAINT fk_clients_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL',
    ],
];
