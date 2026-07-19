<?php

declare(strict_types=1);

// Per-recipient send/open tracking for mail_campaigns (0075). open_token
// is embedded in a 1x1 tracking-pixel URL emailed with the campaign.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS mail_campaign_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            open_token CHAR(64) NOT NULL UNIQUE,
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_campaign (campaign_id),
            CONSTRAINT fk_mail_campaign_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES mail_campaigns(id) ON DELETE CASCADE,
            CONSTRAINT fk_mail_campaign_recipients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
