<?php

declare(strict_types=1);

// Seeds the client-facing email templates for the backgrounded cPanel
// password change (ChangeServicePasswordJob):
//
//   service_password_changed        — the WHM passwd call succeeded; tells the
//                                     client their cPanel password was reset
//   service_password_change_failed  — the passwd call failed; carries the
//                                     exact reason in {{error}} so the client
//                                     can retry or contact support
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_password_changed',
            'Service Password Changed',
            'Your {{service_name}} password has been updated',
            '<p>Hi {{first_name}},</p><p>Your password for <strong>{{service_name}}</strong>{{domain_label}} has been changed successfully.</p><p>Use your cPanel username <strong>{{username}}</strong> with the new password to log in.</p><p>You can manage this service any time in your client area: <a href="{{service_url}}">View service</a></p><p>If you did not request this change, please contact support immediately.</p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_password_change_failed',
            'Service Password Change Failed',
            'Your {{service_name}} password could not be changed',
            '<p>Hi {{first_name}},</p><p>We could not change the password for <strong>{{service_name}}</strong>.</p><p><strong>Reason:</strong> {{error}}</p><p>Please try again from your client area (<a href="{{service_url}}">view service</a>) or contact support and we will help.</p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
