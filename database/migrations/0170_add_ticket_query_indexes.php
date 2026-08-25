<?php

declare(strict_types=1);

// Ticket-list / ticket-detail performance hardening. The admin ticket list
// orders by status priority + updated_at, the mail-piping flood guard counts
// open tickets by sender email, the escalation job scans last_reply_at, and
// the detail page loads replies by (ticket_id, created_at). On a large
// tickets table (e.g. after a mail-piping flood) those queries were doing
// full scans / filesorts, which can stall the request long enough for
// Cloudflare to return 522. These indexes keep them bounded, and the email
// lowercase-normalisation lets the sender-count query hit the new index
// instead of wrapping the column in LOWER() (which would defeat it).

use CodeVault\Database;

$indexExists = static function (Database $db, string $table, string $index): bool {
    return $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$table, $index]
    ) !== null;
};

return [
    'up' => [
        // Emails are compared case-insensitively (the flood guard uses a
        // lowercased lookup). Normalise what's already stored so that lookup
        // can use an index rather than LOWER(column) over every row.
        'UPDATE tickets SET email = LOWER(email) WHERE email <> LOWER(email)',

        static function (Database $db) use ($indexExists): void {
            $indexes = [
                'idx_tickets_email' => ['tickets', 'email'],
                'idx_tickets_updated_at' => ['tickets', 'updated_at'],
                'idx_tickets_status_updated' => ['tickets', 'status, updated_at'],
                'idx_tickets_last_reply' => ['tickets', 'last_reply_at'],
                'idx_replies_ticket_created' => ['ticket_replies', 'ticket_id, created_at'],
            ];

            foreach ($indexes as $name => [$table, $columns]) {
                if ($indexExists($db, $table, $name)) {
                    continue;
                }

                $db->statement("ALTER TABLE {$table} ADD INDEX {$name} ({$columns})");
            }
        },
    ],
];
