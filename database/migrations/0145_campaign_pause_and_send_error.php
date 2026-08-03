<?php

declare(strict_types=1);

use CodeVault\Database;

// Three additions to mail campaigns, all in service of the same request: let
// an admin stop a sending campaign, see why it stopped, fix it, and resume.
//
// 1. `mail_campaigns.status` gains 'paused'. The cron's dispatch query and its
//    drain-finaliser both already filter on `status = 'sending'`, so a
//    'paused' campaign is automatically left alone by both — no other code
//    has to learn about the new state, it just falls out of the WHERE clause
//    the moment the status changes.
//
// 2. `mail_campaign_recipients.email_log_id` links a recipient to the row
//    EmailDispatcher::sendRaw() wrote in `email_log` for that send.
//
//    This exists because `sent_at` being set has never meant "confirmed
//    delivered" — it means "handed to the queue". SendEmailJob::handle()
//    deliberately catches and swallows the mailer's exception itself (so one
//    bad address doesn't crash a worker processing a hundred others), and
//    with the Redis queue driver the job may not even run until well after
//    dispatchQueued() has already returned. Neither a try/catch nor an
//    immediate status check inside dispatchQueued() can see a real delivery
//    failure in either case — the true outcome only exists in `email_log`,
//    written whenever the job actually runs. This column is what lets the
//    campaign show page, and the auto-pause check below, read that real
//    outcome later instead of guessing at send time.
//
// 3. `mail_campaign_recipients.send_error` mirrors the linked email_log row's
//    error for a recipient once it is known to have failed — the "why" an
//    admin needs to review before resending. Kept as its own column (rather
//    than joining on every read) mainly so the failed-count check that
//    decides whether to auto-pause a campaign is one indexed COUNT, not a
//    join across every pending recipient on every cron tick.

return [
    'up' => [
        "ALTER TABLE mail_campaigns MODIFY COLUMN status ENUM('draft', 'sending', 'paused', 'sent') NOT NULL DEFAULT 'draft'",
        static function (Database $db): void {
            foreach (['email_log_id' => 'INT UNSIGNED NULL AFTER open_token', 'send_error' => 'TEXT NULL AFTER sent_at'] as $column => $definition) {
                $exists = $db->selectOne(
                    'SELECT 1 AS y FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    ['mail_campaign_recipients', $column]
                );

                if ($exists !== null) {
                    continue;
                }

                $db->statement("ALTER TABLE mail_campaign_recipients ADD COLUMN {$column} {$definition}");
            }
        },
    ],
];
