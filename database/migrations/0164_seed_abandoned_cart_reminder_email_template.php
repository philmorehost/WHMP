<?php

declare(strict_types=1);

// Seeds the "abandoned cart reminder" template — the email AbandonedCartJob
// sends when a cart has sat untouched for the idle threshold.
//
// The markup itself lives in database/templates/abandoned_cart_reminder_body.php
// so this migration can't drift from what's actually sent.
//
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

$body = require __DIR__ . '/../templates/abandoned_cart_reminder_body.php';

return [
    'up' => [
        sprintf(
            "INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at)
             VALUES ('abandoned_cart_reminder', 'Abandoned Cart Reminder', 'Your {{company_name}} cart is waiting', %s, NOW(), NOW())
             ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()",
            "'" . str_replace("'", "''", $body) . "'"
        ),
    ],
];
