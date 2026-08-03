<?php

declare(strict_types=1);

// Re-asserts the cron activity report template body, same pattern as 0130.
//
// The body now includes a "Pruning & Cleanup" section (services terminated,
// services pruned, invoices auto-cancelled, domains pruned) — counters that
// ServicePruningJob, ServiceTerminationJob, StaleInvoiceCancellationJob and
// DomainPruningJob were already recording via ReportsCronStats, but which
// the report never surfaced. 0128/0130 already ran on any environment that
// has this template, so only a fresh re-assertion picks up the new markup.

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
