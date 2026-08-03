<?php

declare(strict_types=1);

// In-app client notification center: an admin can message one client, a
// hand-picked set, or every client, and every system email addressed to a
// client is mirrored here too — so a client whose registered (often custom,
// sometimes broken) email address never actually delivers still sees the
// same content the moment they log in. One `notifications` row per send
// (admin broadcast or single mirrored email) with a `notification_recipients`
// child row per client, mirroring the mail_campaigns/mail_campaign_recipients
// split — one row regardless of audience size, read state tracked per client.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            source ENUM('admin', 'system_email') NOT NULL DEFAULT 'admin',
            email_log_id BIGINT UNSIGNED NULL,
            created_by_admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT fk_notifications_email_log FOREIGN KEY (email_log_id) REFERENCES email_log(id) ON DELETE SET NULL,
            CONSTRAINT fk_notifications_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS notification_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            notification_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            read_at DATETIME NULL,
            reply_ticket_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_client_unread (client_id, read_at),
            INDEX idx_notification (notification_id),
            CONSTRAINT fk_notification_recipients_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
            CONSTRAINT fk_notification_recipients_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
