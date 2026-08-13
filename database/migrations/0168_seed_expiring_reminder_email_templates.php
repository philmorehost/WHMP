<?php

declare(strict_types=1);

// Seeds the email templates for the admin "email clients expiring in 7 days"
// feature (ExpiringReminderJob):
//
//   expiring_reminder          — sent to each affected client, personalized
//                                with their own service/domain names, due
//                                dates and amounts ({{items_html}}) plus the
//                                admin's promotional message ({{promo_message}})
//   expiring_reminder_report   — sent to every admin when the background job
//                                finishes, with how many were sent/skipped
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'expiring_reminder',
            'Account Renewal Reminder',
            'Your account renewals are coming up',
            '<p>Hi {{first_name}},</p><p>The following items on your account are renewing within the next 7 days:</p>{{items_html}}<p>{{promo_message}}</p><p>Keep everything running without interruption — you can review and pay your invoices here: <a href="{{billing_url}}">My Invoices</a></p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'expiring_reminder_report',
            'Admin Expiring Reminder Report',
            'Expiring Reminder Emails Sent',
            '<p>Hello Admin,</p><p>The expiring-account reminder run has finished in the background.</p><p><strong>{{sent}}</strong> email(s) sent to <strong>{{total}}</strong> client(s){{skipped_note}}.</p><p>View the expiring accounts page here: <a href="{{admin_url}}">Expiring Account Reminders</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
