<?php

declare(strict_types=1);

// Invalid Email Blocker (addon): an email skipped because its address was marked
// Invalid by Email Validation is still written to email_log — with this new
// 'suppressed' status and the reason in `error` — so the log keeps showing every
// message the system meant to send, and why some were not sent.
//
// 'suppressed' is deliberately NOT 'failed': campaign auto-pause and the email
// validation scan both count 'failed' rows as real delivery failures, and a
// message we chose not to send is neither.

return [
    'up' => [
        "ALTER TABLE email_log MODIFY COLUMN status ENUM('queued', 'sent', 'failed', 'suppressed') NOT NULL DEFAULT 'queued'",
    ],
];
