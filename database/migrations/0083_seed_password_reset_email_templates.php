<?php

declare(strict_types=1);

// Seeds the two templates EmailDispatcher::sendTemplate() looks up when
// AuthController/ClientAuthController::sendResetLink() fires (R14).

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'client_password_reset',
            'Client Password Reset',
            'Reset your {{company_name}} password',
            '<p>Hi {{first_name}},</p><p>We received a request to reset your password. Click the link below to choose a new one — this link expires in 60 minutes and can only be used once:</p><p><a href="{{reset_url}}">{{reset_url}}</a></p><p>If you didn\'t request this, you can safely ignore this email — your password will not be changed.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'admin_password_reset',
            'Admin Password Reset',
            'Reset your {{company_name}} admin password',
            '<p>Hi {{display_name}},</p><p>We received a request to reset your admin password. Click the link below to choose a new one — this link expires in 60 minutes and can only be used once:</p><p><a href="{{reset_url}}">{{reset_url}}</a></p><p>If you didn\'t request this, you can safely ignore this email — your password will not be changed.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
