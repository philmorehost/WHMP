<?php

declare(strict_types=1);

// Senders mail piping must ignore — bounce loops (Mailer-Daemon), spam,
// or wrong-party mail that keeps landing in the support inbox as tickets.
// `pattern` is a lowercased email address that may contain '*' wildcards
// (e.g. "*@pmhserver.name.ng"), and is matched against the From address of
// each piped message before anything is created. `source_ticket_id` is set
// when an admin blocks a sender straight from a ticket's page; `created_by`
// is the admin id. No FK on either — tickets get deleted, admins can be
// deleted, and a block entry must survive both.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS blocked_email_senders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pattern VARCHAR(191) NOT NULL,
            reason VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            source_ticket_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_blocked_email_senders_pattern (pattern)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
