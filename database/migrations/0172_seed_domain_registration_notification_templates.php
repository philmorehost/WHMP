<?php

declare(strict_types=1);

// Seeds the domain-registration notification templates used by
// AcceptOrderJob after it registers a pending domain on an accepted order.
//
//   domain_registered              — sent to the CLIENT on success, with the
//                                    next renewal date so they know what they
//                                    are billed for later.
//   admin_domain_registered        — sent to every admin on success, with the
//                                    renewal date and a link to the domain.
//   admin_domain_registration_failed — sent to every admin on failure, with
//                                    the EXACT registrar/API error message so
//                                    staff can diagnose without guessing.
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'domain_registered',
            'Domain Registered',
            'Your Domain {{domain_name}} Has Been Registered',
            '<p>Hi {{first_name}},</p><p>Great news — your domain <strong>{{domain_name}}</strong> has been successfully registered with {{registrar}}.</p><p>It is now active as of {{registration_date}}.</p><p><strong>Next renewal date:</strong> {{renewal_date}}</p><p>You can manage your domain (nameservers, WHOIS, auto-renew and more) any time from your client area:</p><p><a href="{{client_domains_url}}">Manage {{domain_name}}</a></p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'admin_domain_registered',
            'Admin Domain Registered',
            'Domain Registered: {{domain_name}}',
            '<p>Hello Admin,</p><p>Domain <strong>{{domain_name}}</strong> was registered successfully via the {{registrar}} API.</p><p>Order #{{order_id}} &middot; Registration date: {{registration_date}} &middot; Next renewal date: {{renewal_date}}</p><p>Review it here: <a href="{{domain_url}}">Manage {{domain_name}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'admin_domain_registration_failed',
            'Admin Domain Registration Failed',
            'Domain Registration Failed: {{domain_name}}',
            '<p>Hello Admin,</p><p>Domain <strong>{{domain_name}}</strong> could not be registered via the {{registrar}} API.</p><p>Order #{{order_id}}</p><p><strong>Registrar/API error:</strong> {{error}}</p><p>Retry the registration from the domain page: <a href="{{domain_url}}">Retry {{domain_name}}</a></p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
