<?php

declare(strict_types=1);

// Which server group a product provisions onto. NULL = not a provisioned
// product (e.g. a one-time digital good) — the provisioning engine skips
// products with no server group instead of erroring.

return [
    'up' => [
        'ALTER TABLE products ADD COLUMN server_group_id INT UNSIGNED NULL AFTER product_group_id',
        'ALTER TABLE products ADD CONSTRAINT fk_products_server_group FOREIGN KEY (server_group_id) REFERENCES server_groups(id) ON DELETE SET NULL',
    ],
];
