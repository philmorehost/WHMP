<?php

declare(strict_types=1);

// The "here is your server" email a client gets once their order is approved.
//
// Nothing sent the client their access details before this: order acceptance
// provisioned the service and fired ORDER_ACCEPTED, but no listener told the
// client the hostname, IP, or login they had just paid for.
//
// Rows are keyed by `key` and the table has a unique index on it, so INSERT
// IGNORE keeps this migration safe to re-run and, more importantly, will not
// overwrite an admin's own edits to the template if they have already
// customised it.

$body = '<p>Hi {{first_name}},</p>'
    . '<p>Your order has been approved and <strong>{{product_name}}</strong> is ready to use. Here are your access details:</p>'
    . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">'
    . '<tr><td><strong>Product</strong></td><td>{{product_name}}</td></tr>'
    . '<tr><td><strong>Domain</strong></td><td>{{domain}}</td></tr>'
    . '<tr><td><strong>Hostname</strong></td><td>{{hostname}}</td></tr>'
    . '<tr><td><strong>Primary IP</strong></td><td>{{primary_ip}}</td></tr>'
    . '<tr><td><strong>Additional IPs</strong></td><td>{{assigned_ips}}</td></tr>'
    . '<tr><td><strong>Username</strong></td><td>{{username}}</td></tr>'
    . '<tr><td><strong>Password</strong></td><td>{{password}}</td></tr>'
    . '<tr><td><strong>Control panel</strong></td><td>{{control_panel_url}}</td></tr>'
    . '<tr><td><strong>Nameservers</strong></td><td>{{nameservers}}</td></tr>'
    . '</table>'
    . '<p><strong>Please change this password the first time you log in.</strong> It was sent by email, so treat it as temporary.</p>'
    . '<p>You can view these details any time in your client area: {{service_url}}</p>'
    . '<p>If anything does not work, reply to this email or open a ticket and we will help.</p>'
    . '<p>Thanks,<br>{{company_name}}</p>';

return [
    'up' => [
        static function (CodeVault\Database $db) use ($body): void {
            $db->statement(
                'INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [
                    'service_details',
                    'Service Login / Server Details',
                    'Your {{product_name}} is ready — access details inside',
                    $body,
                ]
            );
        },
    ],
];
