<?php

declare(strict_types=1);

// Seeds the admin notification templates for backgrounded service actions —
// the Create Account and package-upgrade jobs that used to block the admin's
// browser now run in the queue worker and report the outcome by email:
//
//   service_account_created        — background createacct succeeded
//   service_account_create_failed  — background createacct failed (or the job
//                                    crashed); body carries {{error}}
//   service_package_upgraded       — background changepackage succeeded
//   service_package_upgrade_failed — background changepackage failed; body
//                                    carries {{error}}
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_account_created',
            'Admin Service Account Created',
            'Service #{{service_id}} Account Created',
            '<p>Hello Admin,</p><p>The cPanel account for service <strong>#{{service_id}} ({{service_name}})</strong> — client <strong>{{client}}</strong> — was created successfully in the background.</p><p><strong>Result:</strong> {{message}}</p><p>View the service here: <a href="{{service_url}}">Service #{{service_id}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_account_create_failed',
            'Admin Service Account Creation Failed',
            'Service #{{service_id}} Account Creation Failed',
            '<p>Hello Admin,</p><p>The cPanel account for service <strong>#{{service_id}} ({{service_name}})</strong> could not be created in the background.</p><p><strong>Error:</strong> {{error}}</p><p>Please review the service and retry account creation once the issue is resolved: <a href="{{service_url}}">Service #{{service_id}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_package_upgraded',
            'Admin Service Package Upgraded',
            'Service #{{service_id}} Package Upgraded',
            '<p>Hello Admin,</p><p>The hosting package for service <strong>#{{service_id}} ({{service_name}})</strong> — client <strong>{{client}}</strong> — was switched to <strong>{{package}}</strong> in the background.</p><p><strong>Result:</strong> {{message}}</p><p>View the service here: <a href="{{service_url}}">Service #{{service_id}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'service_package_upgrade_failed',
            'Admin Service Package Upgrade Failed',
            'Service #{{service_id}} Package Upgrade Failed',
            '<p>Hello Admin,</p><p>The hosting package for service <strong>#{{service_id}} ({{service_name}})</strong> could not be switched to <strong>{{package}}</strong> in the background.</p><p><strong>Error:</strong> {{error}}</p><p>Please review the service and retry the package change once the issue is resolved: <a href="{{service_url}}">Service #{{service_id}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
