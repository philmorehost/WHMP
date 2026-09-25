<?php

declare(strict_types=1);

use CodeVault\Database;

// Lets a client say WHICH of their services or domains a ticket is about.
//
// Before this the only hint staff had was prose in the message body ("my
// hosting is down" — which hosting?), so an admin had to read the whole
// thread, guess the item, and then go hunting for it in Services/Domains.
// With the id on the ticket the client portal can show the item it relates
// to and the admin ticket page can link straight to /admin/services/{id} or
// /admin/domains/{id} to review it.
//
// Both columns are nullable on purpose: plenty of tickets are not about one
// specific item (a billing question, a general enquiry), and every ticket
// piped in from email and every one opened by a non-support flow (the
// notification centre, "this action needs a human" service actions) has no
// item to name. NULL means "not about a specific service/domain" and the
// pages simply omit the row.
//
// ON DELETE SET NULL rather than CASCADE: a ticket is a conversation record
// and must outlive the service it was raised about — deleting a terminated
// service should not silently delete the support history around it. The ids
// are also untrusted input when a client submits the form, so the controller
// re-checks ownership before writing them (see ClientTicketController).
//
// Guarded through INFORMATION_SCHEMA (see 0179/0182) rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only), so this is portable and safe to
// re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $columnExists = static function (Database $db, string $column): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    ['tickets', $column]
                ) !== null;
            };

            $constraintExists = static function (Database $db, string $constraint): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['tickets', $constraint]
                ) !== null;
            };

            $indexExists = static function (Database $db, string $index): bool {
                return $db->selectOne(
                    'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    ['tickets', $index]
                ) !== null;
            };

            if (!$columnExists($db, 'service_id')) {
                $db->statement('ALTER TABLE tickets ADD COLUMN service_id INT UNSIGNED NULL AFTER department_id');
            }

            if (!$columnExists($db, 'domain_id')) {
                $db->statement('ALTER TABLE tickets ADD COLUMN domain_id INT UNSIGNED NULL AFTER service_id');
            }

            if (!$indexExists($db, 'idx_service')) {
                $db->statement('ALTER TABLE tickets ADD INDEX idx_service (service_id)');
            }

            if (!$indexExists($db, 'idx_domain')) {
                $db->statement('ALTER TABLE tickets ADD INDEX idx_domain (domain_id)');
            }

            if (!$constraintExists($db, 'fk_tickets_service')) {
                $db->statement('ALTER TABLE tickets ADD CONSTRAINT fk_tickets_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL');
            }

            if (!$constraintExists($db, 'fk_tickets_domain')) {
                $db->statement('ALTER TABLE tickets ADD CONSTRAINT fk_tickets_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL');
            }
        },
    ],
];
