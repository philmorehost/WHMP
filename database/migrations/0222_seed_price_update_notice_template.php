<?php

declare(strict_types=1);

// Seeds the "price_update_notice" template used by the bulk price update
// addon (Admin → Bulk Price Updates): when the admin changes product and/or
// TLD pricing, every client who subscribes to an affected product or holds a
// domain under an affected extension gets one review notice with THEIR
// affected lines (product/cycle old→new, or the TLD's changed price fields).
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'price_update_notice',
            'Price Update Notice',
            'Important Update: Review of {{company_name}} Prices',
            '<p>Hi {{first_name}},</p><p>We are writing to let you know that we have reviewed and updated some of our prices, effective <strong>{{effective_date}}</strong>. This notice covers only the services and domain extensions you use with us — your next invoice or renewal will reflect any change that applies to you.</p>{{product_changes}}{{domain_changes}}<p>You can review your current services and their renewal prices any time from your client area:</p><p><a href="{{client_services_url}}">My Services</a> &middot; <a href="{{client_domains_url}}">My Domains</a></p><p>If you have any questions about this price review, please reach out — we are happy to help.</p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
