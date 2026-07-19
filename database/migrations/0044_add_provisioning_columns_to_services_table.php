<?php

declare(strict_types=1);

// `server_id` is the specific server (chosen from the product's server
// group at provisioning time) this service actually lives on.
// `username` is the control-panel account username. `provisioning_error`
// holds the last failure message so a failed create/suspend/etc. is
// retryable instead of silently stuck.

return [
    'up' => [
        'ALTER TABLE services ADD COLUMN server_id INT UNSIGNED NULL AFTER product_id',
        'ALTER TABLE services ADD COLUMN username VARCHAR(100) NULL AFTER server_id',
        'ALTER TABLE services ADD COLUMN provisioning_error TEXT NULL AFTER status',
        'ALTER TABLE services ADD CONSTRAINT fk_services_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL',
    ],
];
