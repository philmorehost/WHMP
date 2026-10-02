<?php

declare(strict_types=1);

// That a mailbox was CREATED for a store, and for which domain.
//
// WHY THIS IS NOT REUSED FROM domain_provisioned_host
//
// They are provisioned at different times, they fail independently, and the question the
// daily job asks is a different one: "have we already made a mailbox for THIS domain?" A
// store can be serving on the panel for weeks before a mailbox is wanted, and a store that
// changes domain needs a mailbox on the new one while the old record still describes the
// web server.
//
// Without this record the job cannot tell "no mailbox yet" from "tried and it is not
// wanted", so it would call the panel about the same store every single day. The panel
// answers "already exists", which is TRUE and useless — it costs a daily API call and a
// daily log line per store, and it hides the one case that matters, which is a store whose
// domain is not aligned yet and which is therefore correctly waiting.
//
// mailbox_provision_error is separate from mailbox_host for the same reason
// domain_provision_error is separate from domain_provisioned_host: "we tried and the panel
// refused" is not the same as "it is there". A failure records the error and leaves the
// host NULL, so the job retries — which is what makes it self-heal once the panel is fixed,
// and is why the error is surfaced in the portal rather than only logged.
//
// No backfill. Every existing store has no mailbox of its own, which is already the correct
// starting state: no support_email means its customers see the store's NAME on mail carried
// by the platform's authenticated address.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $hasColumn = static function (\CodeVault\Database $db, string $table, string $column): bool {
                return (string) ($db->selectOne(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )['COLUMN_NAME'] ?? '') !== '';
            };

            $columns = [
                'mailbox_host VARCHAR(255) NULL',
                'mailbox_provisioned_at DATETIME NULL',
                'mailbox_provision_error TEXT NULL',
            ];

            foreach ($columns as $definition) {
                $name = strstr($definition, ' ', true);

                if (!$hasColumn($db, 'resellers', (string) $name)) {
                    $db->statement('ALTER TABLE resellers ADD COLUMN ' . $definition);
                }
            }
        },
    ],
];
