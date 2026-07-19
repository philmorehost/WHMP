<?php

declare(strict_types=1);

// Links a ledger entry back to the formal credit note document that
// produced it (R18) — nullable, since most ledger entries (manual "Grant
// Credit", credit spent toward an invoice) have no credit note at all.

return [
    'up' => [
        'ALTER TABLE client_credit_ledger ADD COLUMN credit_note_id INT UNSIGNED NULL AFTER invoice_id',
        'ALTER TABLE client_credit_ledger ADD CONSTRAINT fk_ledger_credit_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE SET NULL',
    ],
];
