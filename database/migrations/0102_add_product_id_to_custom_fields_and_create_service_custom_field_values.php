<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE products ADD COLUMN type ENUM(\'shared\', \'reseller\', \'vps\', \'dedicated\', \'other\') NOT NULL DEFAULT \'other\' AFTER product_group_id',
        'ALTER TABLE services ADD COLUMN hostname VARCHAR(255) NULL AFTER domain',
        'ALTER TABLE services ADD COLUMN password VARCHAR(255) NULL AFTER hostname',
        'ALTER TABLE custom_fields ADD COLUMN product_id INT UNSIGNED NULL AFTER field_for',
        'ALTER TABLE custom_fields ADD CONSTRAINT fk_custom_fields_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE',
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS service_custom_field_values (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NOT NULL,
            custom_field_id INT UNSIGNED NOT NULL,
            value TEXT NULL,
            UNIQUE KEY uq_service_field (service_id, custom_field_id),
            CONSTRAINT fk_scfv_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
            CONSTRAINT fk_scfv_field FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
