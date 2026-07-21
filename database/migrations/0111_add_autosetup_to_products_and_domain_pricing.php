<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        ALTER TABLE products 
        ADD COLUMN autosetup ENUM('order', 'payment', 'on_accept', 'off') NOT NULL DEFAULT 'payment' AFTER server_group_id
        SQL,
        <<<'SQL'
        ALTER TABLE domain_pricing 
        ADD COLUMN autosetup_registration ENUM('order', 'payment', 'on_accept', 'off') NOT NULL DEFAULT 'payment' AFTER category,
        ADD COLUMN autosetup_transfer ENUM('order', 'payment', 'on_accept', 'off') NOT NULL DEFAULT 'payment' AFTER autosetup_registration
        SQL,
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'admin_pending_order_approval',
            'Admin Pending Order Approval Notification',
            'Pending Order Approval Required: Order #{{order_id}}',
            '<p>Hello Admin,</p><p>Order #{{order_id}} placed by <strong>{{client_name}}</strong> ({{client_email}}) contains products/domains requiring manual review and approval before provisioning.</p><p>Total Order Amount: ${{order_total}}</p><p>Please log in to the admin panel to review and accept the order.</p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
