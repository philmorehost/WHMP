<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_custom_field_values (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            custom_field_id INT UNSIGNED NOT NULL,
            value TEXT NULL,
            UNIQUE KEY uq_client_field (client_id, custom_field_id),
            CONSTRAINT fk_ccfv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_ccfv_field FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
