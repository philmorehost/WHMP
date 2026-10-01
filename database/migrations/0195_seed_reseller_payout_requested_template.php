<?php

declare(strict_types=1);

// Seeds the template emailed to the admins who can actually release a reseller
// payout, when a reseller requests one.
//
// WHY AN EMAIL AND NOT AN IN-APP BADGE
//
// There is no admin notification centre in this codebase — the client portal has
// one, the admin side has never had one — so "notify the admin" here means the
// same thing it means everywhere else on the admin side: an email to the people
// who can act (see AdminRepository::withPermission()). Building a badge instead
// would mean a new table, new read/unread state and a new page, for one message;
// the email costs a template and reaches an admin who is not logged in, which is
// the case that matters for a payout waiting on a manual transfer.
//
// WHO RECEIVES IT is decided in code, not here: every admin holding
// `resellers.manage` (which is what gates the payout queue) plus every super
// admin, because is_super_admin bypasses the permission matrix. That is why the
// body never says "you are the only recipient" — it cannot know.
//
// {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert rather than plain INSERT: this runs through the automatic migrator that
// heals the schema on boot, so it has to tolerate re-application.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'reseller_payout_requested',
            'Admin Reseller Payout Requested',
            'Payout request #{{payout_id}} — {{amount}} {{currency}} ({{store_name}})',
            '<p>Hello,</p><p><strong>{{store_name}}</strong> has requested a reseller payout. The funds are already set aside from their available balance, so nothing further can be spent against them.</p><ul><li><strong>Amount:</strong> {{amount}} {{currency}}</li><li><strong>Reseller:</strong> {{reseller_name}} ({{reseller_email}})</li><li><strong>Request:</strong> #{{payout_id}}</li><li><strong>Requested:</strong> {{requested_at}}</li></ul><p>Payment is a <strong>manual bank transfer</strong>: make the transfer, then record its reference in the payout queue. The reference is required — it is what ties the payout to a bank line, and without it the request is a record of an intention rather than of a payment.</p><p><a href="{{queue_url}}">Open the payout queue</a></p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
