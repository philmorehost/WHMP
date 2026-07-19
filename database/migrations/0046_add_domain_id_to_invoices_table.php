<?php

declare(strict_types=1);

// Mirrors 0034's service_id addition — links an auto-generated domain
// renewal invoice back to the domain it's for, so duplicate-generation
// prevention is the same (domain_id + due_date) existence check.

return [
    'up' => [
        'ALTER TABLE invoices ADD COLUMN domain_id INT UNSIGNED NULL AFTER service_id',
        'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL',
        'ALTER TABLE invoices ADD INDEX idx_domain_due (domain_id, due_date)',
    ],
];
