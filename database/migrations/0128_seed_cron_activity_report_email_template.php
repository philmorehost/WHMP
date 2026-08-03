<?php

declare(strict_types=1);

// Seeds the cron automation daily activity report template — the email
// CronActivityReportJob sends to the admin each day.
//
// The markup itself lives in database/templates/cron_activity_report_body.php
// so this migration and the corrective one (0130) can't drift apart.
//
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

$body = require __DIR__ . '/../templates/cron_activity_report_body.php';

return [
    'up' => [
        sprintf(
            "INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at)
             VALUES ('cron_activity_report', 'Cron Automation Activity Report', 'Daily Automation Report for {{report_date}}', %s, NOW(), NOW())
             ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()",
            "'" . str_replace("'", "''", $body) . "'"
        ),
    ],
];
