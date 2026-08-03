<?php

declare(strict_types=1);

// The "here's your verification code" email for the OTP registration step.
//
// Rows are keyed by `key` and the table has a unique index on it, so INSERT
// IGNORE keeps this migration safe to re-run and won't overwrite an admin's
// own edits to the template if they've already customised it.

$body = '<p>Hi {{first_name}},</p>'
    . '<p>Use this code to verify your email address and finish creating your {{company_name}} account:</p>'
    . '<p style="font-size:28px;font-weight:700;letter-spacing:4px;text-align:center;margin:24px 0;">{{code}}</p>'
    . '<p>This code expires in {{expiry_minutes}} minutes. If you did not try to create an account, you can ignore this email.</p>'
    . '<p>Thanks,<br>{{company_name}}</p>';

return [
    'up' => [
        static function (CodeVault\Database $db) use ($body): void {
            $db->statement(
                'INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [
                    'client_registration_otp',
                    'Registration Verification Code',
                    'Your verification code: {{code}}',
                    $body,
                ]
            );
        },
    ],
];
