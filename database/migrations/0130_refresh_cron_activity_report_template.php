<?php

declare(strict_types=1);

// Re-asserts the cron activity report template body.
//
// Migration 0128 shipped for a while in a state that couldn't be applied (a
// nowdoc whose body was indented less than its closing marker, so the file
// raised a ParseError on require) and, in an environment that did apply an
// early version, seeded markup containing {{#if}}/{{#each}} blocks. The email
// renderer is a flat strtr(), so those blocks would reach the admin as
// literal "{{#if job_errors}}" text.
//
// Because 0128 is recorded as applied once it succeeds, it can never correct
// such a database on its own. This migration re-applies the current body from
// the same shared source, so every environment converges regardless of which
// version of 0128 it happened to run.

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
