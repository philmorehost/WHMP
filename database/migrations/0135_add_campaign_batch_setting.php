<?php

declare(strict_types=1);

// How many campaign emails the cron dispatches per run.
//
// "Send Now" used to loop every recipient inside the request, so a campaign to
// a few hundred clients meant hundreds of inserts and queue pushes before the
// page responded — slow, and liable to hit the PHP time limit half-way
// through. Sending is now queued and drained by the cron a few at a time, so
// the mail server sees a steady trickle instead of a burst.
//
// 5 per minute (300/hour) is a deliberately conservative default that suits
// shared hosting rate limits; raise it if the sending host allows more.

return [
    'up' => [
        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at)
        VALUES ('marketing.campaign_batch_size', '5', NOW())
        SQL,
    ],
];
