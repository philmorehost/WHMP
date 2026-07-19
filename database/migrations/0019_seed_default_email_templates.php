<?php

declare(strict_types=1);

// Seeds the first template so the async-email pipeline has something to
// fire against immediately (blueprint §5). More templates get seeded
// alongside the events that need them (order confirmation in R4, invoice
// reminders in R5, etc.) rather than all guessed up front.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'client_welcome',
            'Client Welcome Email',
            'Welcome to {{company_name}}, {{first_name}}!',
            '<p>Hi {{first_name}},</p><p>Your account with {{company_name}} has been created. You can log in using the email address {{email}}.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
