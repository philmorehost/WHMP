<?php

declare(strict_types=1);

use CodeVault\Database;

// Two independent changes, batched because they both touch email_templates.
//
// 1. Inserts a `{{access_instructions}}` slot into the `service_details`
//    template body, right after the details table. ServiceDetailsNotifier now
//    fills it with cPanel-specific "how to log in via the Client Area"
//    guidance when the service sits on a cPanel server, and with an empty
//    string otherwise — the placeholder sits bare in the body (not wrapped in
//    a <p>), so an empty value makes the whole paragraph disappear rather
//    than leaving a blank one.
//
//    Guarded the same way 0141 already promises: the UPDATE only matches rows
//    whose name/body are still EXACTLY the original seed, so an admin who has
//    already customised this template keeps their own wording untouched. Also
//    renames "Service Login / Server Details" to "Service Login / Access
//    Details" — this template covers any product with login details worth
//    emailing, not only physical servers.
//
// 2. Seeds ticket_opened / admin_ticket_opened / ticket_reply /
//    admin_ticket_reply into email_templates via INSERT IGNORE.
//
//    These four are sent by Kernel.php's TICKET_OPEN/TICKET_REPLY listeners,
//    but were only ever created just-in-time by an inline $ensureTemplate()
//    closure the first time each hook fired — never through a migration like
//    every other template in this app. Until that first ticket event, the row
//    simply did not exist, so it could not appear on the admin's Email
//    Templates page (`SELECT * FROM email_templates`) and could not be
//    edited. A fresh install, or one that had not yet had a ticket opened or
//    replied to, had four templates the admin could not see or customise even
//    though the app was already sending them.
//
//    Content is copied verbatim from the $ensureTemplate() calls in
//    Kernel.php so behaviour does not change for anyone already relying on
//    the runtime-seeded row. $ensureTemplate() itself is left in place as a
//    harmless no-op fallback for any environment that runs the hook before
//    ever running this migration.

$oldName = 'Service Login / Server Details';
$newName = 'Service Login / Access Details';

$oldBody = '<p>Hi {{first_name}},</p>'
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

$newBody = '<p>Hi {{first_name}},</p>'
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
    . '{{access_instructions}}'
    . '<p><strong>Please change this password the first time you log in.</strong> It was sent by email, so treat it as temporary.</p>'
    . '<p>You can view these details any time in your client area: {{service_url}}</p>'
    . '<p>If anything does not work, reply to this email or open a ticket and we will help.</p>'
    . '<p>Thanks,<br>{{company_name}}</p>';

$ticketTemplates = [
    [
        'ticket_opened',
        'Ticket Opened Confirmation',
        '[Ticket #{{ticket_id}}] {{ticket_subject}}',
        '<p>Dear {{client_name}},</p><p>Thank you for contacting our Support Department. A support ticket has been opened for you.</p><p><strong>Ticket Details:</strong><br>Ticket ID: #{{ticket_id}}<br>Subject: {{ticket_subject}}<br>Department: {{department_name}}</p><p>Our staff will review your request and reply shortly. You can view or update your ticket at any time by logging into the client area.</p><p>Thanks,<br>{{company_name}}</p>',
    ],
    [
        'admin_ticket_opened',
        'Admin Ticket Opened Notification',
        '[New Ticket #{{ticket_id}}] {{ticket_subject}}',
        '<p>Hello Admin,</p><p>A new support ticket has been opened by <strong>{{client_name}}</strong> ({{client_email}}).</p><p><strong>Ticket Details:</strong><br>Ticket ID: #{{ticket_id}}<br>Subject: {{ticket_subject}}<br>Department: {{department_name}}</p><p>Please log in to the admin panel to view and reply to the ticket.</p><p>Thanks,<br>{{company_name}} System</p>',
    ],
    [
        'ticket_reply',
        'Ticket Reply Notification',
        '[Ticket #{{ticket_id}}] Re: {{ticket_subject}}',
        '<p>Dear {{client_name}},</p><p>A staff member has replied to your support ticket.</p><p><strong>Latest Response:</strong><br>{{reply_message}}</p><p>You can view the full ticket and reply to it by logging into the client area.</p><p>Thanks,<br>{{company_name}}</p>',
    ],
    [
        'admin_ticket_reply',
        'Admin Ticket Reply Notification',
        '[Ticket Update #{{ticket_id}}] Client Reply: {{ticket_subject}}',
        '<p>Hello Admin,</p><p>Client <strong>{{client_name}}</strong> has replied to support ticket #{{ticket_id}}.</p><p><strong>Latest Response:</strong><br>{{reply_message}}</p><p>Please log in to the admin panel to view and reply to the ticket.</p><p>Thanks,<br>{{company_name}} System</p>',
    ],
];

return [
    'up' => [
        static function (Database $db) use ($oldName, $newName, $oldBody, $newBody): void {
            $db->update(
                'UPDATE email_templates SET name = ?, body_html = ?, updated_at = NOW() WHERE `key` = ? AND name = ? AND body_html = ?',
                [$newName, $newBody, 'service_details', $oldName, $oldBody]
            );
        },
        static function (Database $db) use ($ticketTemplates): void {
            foreach ($ticketTemplates as [$key, $name, $subject, $body]) {
                $db->statement(
                    'INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                    [$key, $name, $subject, $body]
                );
            }
        },
    ],
];
