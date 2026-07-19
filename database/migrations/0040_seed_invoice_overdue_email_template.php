<?php

declare(strict_types=1);

// Dunning basics (blueprint §4.4) reuse the async-email pipeline from R3 —
// the DunningJob cron sweep fires InvoiceOverdue, a hook listener renders
// this template and queues it, same as every other outbound email.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'invoice_overdue',
            'Invoice Overdue Reminder',
            'Invoice INV-{{invoice_id}} is overdue',
            '<p>Hi {{first_name}},</p><p>Invoice INV-{{invoice_id}} for {{total}} was due on {{due_date}} and is still unpaid. Please log in to your account to settle it.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
