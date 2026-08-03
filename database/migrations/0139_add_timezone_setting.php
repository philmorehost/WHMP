<?php

declare(strict_types=1);

// Application timezone.
//
// Nothing ever called date_default_timezone_set(), so PHP fell back to its
// ini default — UTC on most hosts. Every date the app produced was therefore
// UTC while the business runs on local time: the Cron & Automation screen
// reported "server time 09:11" when it was 10:11 in Lagos, and a daily
// automation time of 10:10 actually fired at 11:10 local.
//
// Defaults to UTC so nothing shifts for an install that was already correct;
// the admin picks their own zone in General Settings.

return [
    'up' => [
        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at)
        VALUES ('general.timezone', 'UTC', NOW())
        SQL,
    ],
];
