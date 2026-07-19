<?php

declare(strict_types=1);

// Links an auto-generated renewal invoice back to the service it's for —
// makes duplicate-generation prevention a simple existence check
// (service_id + due_date) instead of fuzzy invoice-item matching.

return [
    'up' => [
        'ALTER TABLE invoices ADD COLUMN service_id INT UNSIGNED NULL AFTER order_id',
        'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL',
        'ALTER TABLE invoices ADD INDEX idx_service_due (service_id, due_date)',
    ],
];
