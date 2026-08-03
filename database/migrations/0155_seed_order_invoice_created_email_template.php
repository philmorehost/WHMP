<?php

declare(strict_types=1);

// Seeds the "order invoice created" template — the email
// AdminOrderController::store() sends a client when an admin places an
// order on their behalf.
//
// The markup itself lives in database/templates/order_invoice_created_body.php
// so this migration can't drift from what's actually sent.
//
// Upsert rather than plain INSERT: this runs through the automatic migrator
// that heals the schema on boot, so it has to tolerate re-application.

$body = require __DIR__ . '/../templates/order_invoice_created_body.php';

return [
    'up' => [
        sprintf(
            "INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at)
             VALUES ('order_invoice_created', 'Order Invoice Created', 'Your invoice for order #{{order_id}}', %s, NOW(), NOW())
             ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()",
            "'" . str_replace("'", "''", $body) . "'"
        ),
    ],
];
