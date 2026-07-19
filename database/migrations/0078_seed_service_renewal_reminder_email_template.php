<?php

declare(strict_types=1);

// Lifecycle email — reuses the same async-email pipeline as the
// invoice_overdue template (0040): RenewalReminderJob renders this and
// queues it via EmailDispatcher, same as every other outbound email.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_renewal_reminder',
            'Service Renewal Reminder',
            'Your {{product_name}} renews on {{due_date}}',
            '<p>Hi {{first_name}},</p><p>This is a reminder that your service "{{product_name}}" is due to renew on {{due_date}} for {{amount}}. No action is needed if you\'d like it to continue — it will renew automatically once the invoice is paid.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
