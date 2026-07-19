<?php

declare(strict_types=1);

// Marketing automation — mass-mail campaigns (blueprint §4.4/§5). A
// campaign targets either every active client or one client group;
// mail_campaign_recipients (0076) tracks per-recipient send/open state.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS mail_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            client_group_id INT UNSIGNED NULL,
            status ENUM('draft', 'sending', 'sent') NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_mail_campaigns_group FOREIGN KEY (client_group_id) REFERENCES client_groups(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
