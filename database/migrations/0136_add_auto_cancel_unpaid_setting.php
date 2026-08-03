<?php

declare(strict_types=1);

// Days after an invoice's due date before it is automatically cancelled.
//
// Long-dead unpaid invoices — usually imported history, or renewals for
// services the client abandoned — never get paid and never go away, so they
// distort the unpaid totals and bury the invoices that do need chasing.
//
// Ships as 0, meaning OFF. Cancelling invoices is customer-visible and not
// something a deploy should quietly start doing to historical billing
// records; the admin turns it on deliberately and picks the window.

return [
    'up' => [
        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at)
        VALUES ('billing.auto_cancel_unpaid_days', '0', NOW())
        SQL,
    ],
];
