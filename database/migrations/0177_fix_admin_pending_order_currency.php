<?php

declare(strict_types=1);

/**
 * The admin "Pending Order Approval" email hardcoded a `$` in front of
 * {{order_total}}, so an order a client placed in Naira (or EUR, etc.) was
 * reported to the admin as "$500.00" even though the real figure was
 * "₦745,000.00". The symbol is now supplied by the caller as part of a fully
 * formatted {{order_total}} (e.g. "₦745,000.00"), so the template carries no
 * currency at all — the email always shows the client's actual currency.
 */
return [
    'up' => [
        <<<'SQL'
        UPDATE email_templates SET body_html = '<p>Hello Admin,</p><p>Order #{{order_id}} placed by <strong>{{client_name}}</strong> ({{client_email}}) contains products/domains requiring manual review and approval before provisioning.</p><p>Total Order Amount: {{order_total}}</p><p>Please log in to the admin panel to review and accept the order.</p><p>Thanks,<br>{{company_name}} System</p>', updated_at = NOW() WHERE `key` = 'admin_pending_order_approval'
        SQL,
    ],
];
