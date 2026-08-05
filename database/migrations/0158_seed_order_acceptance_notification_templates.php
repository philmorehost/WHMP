<?php

declare(strict_types=1);

// Seeds the order-acceptance notification templates — emailed to every admin
// when a queued AcceptOrderJob finishes processing an order in the background.
//
//   order_acceptance_completed — the job ran to completion; the body carries a
//     {{summary}} snippet with either "all provisioned" or the exact per-item
//     failure reasons the job collected.
//   order_acceptance_failed    — the job threw before finishing; the body
//     carries the exact exception message in {{error}}.
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'order_acceptance_completed',
            'Admin Order Acceptance Completed',
            'Order #{{order_id}} Acceptance Completed',
            '<p>Hello Admin,</p><p>Order #{{order_id}} has finished processing in the background.</p>{{summary}}<p>You can review it here: <a href="{{order_url}}">Order #{{order_id}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'order_acceptance_failed',
            'Admin Order Acceptance Failed',
            'Order #{{order_id}} Acceptance Failed',
            '<p>Hello Admin,</p><p>Order #{{order_id}} could not be accepted — the background job failed before finishing.</p><p><strong>Error:</strong> {{error}}</p><p>Please review the order in the admin panel and retry acceptance once the issue is resolved.</p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
