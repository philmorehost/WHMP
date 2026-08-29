<?php

declare(strict_types=1);

// Seeds the two client-facing notification templates used by the admin
// "Generate Invoice" page. The admin decides per invoice whether to email the
// client the invoice itself and/or a payment receipt:
//
//   invoice_created  — the invoice notice (total, due date, client-area link),
//                      sent when the admin opts to "email the invoice".
//   payment_receipt  — the receipt the client can keep, sent when the admin
//                      opts to "email a payment receipt as well".
//
// The {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert so the automatic migrator that heals the schema on boot can re-apply.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'invoice_created',
            'Invoice Created',
            'Invoice INV-{{invoice_id}}',
            '<p>Hi {{first_name}},</p><p>A new invoice has been raised for your account.</p><p><strong>Invoice:</strong> INV-{{invoice_id}}<br><strong>Total:</strong> {{invoice_total}}<br><strong>Due date:</strong> {{due_date}}</p><p>You can view and pay it from your client area: <a href="{{invoice_url}}">Pay INV-{{invoice_id}}</a></p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'payment_receipt',
            'Payment Receipt',
            'Payment Receipt — INV-{{invoice_id}}',
            '<p>Hi {{first_name}},</p><p>Thank you for your payment. Here is your receipt:</p><p><strong>Invoice:</strong> INV-{{invoice_id}}<br><strong>Amount paid:</strong> {{invoice_total}}<br><strong>Date:</strong> {{paid_date}}</p><p>View your invoice any time: <a href="{{invoice_url}}">INV-{{invoice_id}}</a></p><p>Thanks,<br>{{company_name}} Team</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
