<?php

declare(strict_types=1);

// Tickets become a store's business, and the platform gets a queue for the ones a
// store could not finish.
//
// WHY `tickets.reseller_id` IS A STORE AND NOT A CLIENT
//
// Because `clients.reseller_id` (migration 0187) already answers "whose client is
// this" — a store attributes only the accounts IT created, and an existing client
// of ours who buys from a store keeps their owner. So a ticket belongs to the store
// that owns the client, which is one rule rather than a second, possibly
// conflicting, idea of attribution. The stamp is taken from the client at creation
// time (TicketRepository::create), so every path — the portal form, admin-created
// tickets, and mail piping — gets it without having to remember to pass it.
//
// DENORMALISED ON PURPOSE, and the trade-off is real either way. A join against
// `clients.reseller_id` would always be current; a stamp records who was serving
// the customer WHEN THE CONVERSATION HAPPENED. A support history that moves to a
// different store's desk because somebody re-attributed the client is the wrong
// answer for a record of what was said, so the stamp wins. If a client is moved,
// new tickets go to the new store and the old ones stay with the old.
//
// ON DELETE SET NULL, never CASCADE: a conversation with a customer is a record
// with its own value, and deleting a store must not delete what was said to the
// customer. The ticket simply returns to the platform's desk.
//
// ESCALATION IS A MARK, NOT A SECOND PERMISSION SYSTEM
//
// Platform admins can already see every ticket, so "escalated" is not about
// visibility — it is about ATTENTION: a store saying "I cannot resolve this, please
// take it". Which is also why it can be withdrawn, and why the note matters (the
// platform needs to know what was already tried).
//
// BACKFILL. An existing ticket belongs to whichever store owns its client, which
// for a store's customer is exactly what the new rule would have stamped.

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

            $hasIndex = static function (\CodeVault\Database $db, string $table, string $index): bool {
                return $db->select(
                    'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $index]
                ) !== [];
            };

            $hasConstraint = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['tickets', $constraint]
                ) !== [];
            };

            $columns = [
                'reseller_id INT UNSIGNED NULL',
                'escalated_at DATETIME NULL',
                'escalated_note TEXT NULL',
            ];

            foreach ($columns as $definition) {
                $name = strstr($definition, ' ', true);

                if (!$hasColumn($db, 'tickets', (string) $name)) {
                    $db->statement('ALTER TABLE tickets ADD COLUMN ' . $definition);
                }
            }

            // The reseller's list is "my store's tickets, newest first", so the
            // index covers both columns it filters and sorts on.
            if (!$hasIndex($db, 'tickets', 'idx_ticket_reseller')) {
                $db->statement('ALTER TABLE tickets ADD INDEX idx_ticket_reseller (reseller_id, status)');
            }

            // The platform's escalation queue.
            if (!$hasIndex($db, 'tickets', 'idx_ticket_escalated')) {
                $db->statement('ALTER TABLE tickets ADD INDEX idx_ticket_escalated (escalated_at)');
            }

            if (!$hasConstraint($db, 'fk_tickets_reseller')) {
                $db->statement(
                    'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_reseller
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL'
                );
            }

            // A STORE'S REPLY NEEDS ITS OWN AUTHOR TYPE.
            //
            // `TicketService::reply()` derives the new status from the author type
            // ('admin' -> answered, anything else -> customer-reply), and platform
            // replies have to be distinguishable from a store's so that escalation
            // can be dropped when WE answer. Recording a store's reply as 'admin'
            // would make those two indistinguishable; recording it as 'client'
            // would flip the ticket to "customer replied", which is exactly
            // backwards.
            //
            // This fits the column's existing design rather than bending it:
            // ticket_replies.author_type has always been polymorphic with author_id
            // meaning "a client id or an admin id depending on author_type" and no
            // foreign key (see migration 0051). For 'reseller', author_id is the
            // store owner's client id — the human who wrote it, consistent with the
            // other two.
            $addEnumValue = static function (
                \CodeVault\Database $db,
                string $table,
                string $column,
                string $value,
                bool $nullable
            ): void {
                $row = $db->selectOne(
                    'SELECT COLUMN_TYPE AS col_type FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                $type = (string) ($row['col_type'] ?? '');

                // Column absent (an older or odd install): nothing to widen, and
                // failing here would block every later step for no reason.
                if ($type === '') {
                    return;
                }

                $members = [];

                if (preg_match_all("~'((?:[^']|'')*)'~", $type, $matches) !== false) {
                    $members = $matches[1];
                }

                if (in_array($value, $members, true)) {
                    return;
                }

                // UNION the new member in — read from the live definition, never a
                // list written here. MODIFY COLUMN replaces the definition, so a
                // hard-coded ENUM(...) would silently DROP any member an install
                // already had, and MySQL rewrites every row using one to ''. That
                // is a data-destroying "migration" that looks like it worked.
                $members[] = $value;

                $db->statement(
                    'ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $column . ' ENUM('
                    . implode(',', array_map(
                        static fn (string $member): string => "'" . str_replace("'", "''", $member) . "'",
                        $members
                    ))
                    . ') ' . ($nullable ? 'NULL' : 'NOT NULL')
                );
            };

            $addEnumValue($db, 'ticket_replies', 'author_type', 'reseller', false);
            $addEnumValue($db, 'tickets', 'last_reply_by', 'reseller', true);

            // Only where the column is actually there — on an install where the
            // ALTER above failed, this UPDATE would fail for an unrelated reason.
            if ($hasColumn($db, 'tickets', 'reseller_id') && $hasColumn($db, 'clients', 'reseller_id')) {
                $db->statement(
                    'UPDATE tickets t
                     JOIN clients c ON c.id = t.client_id
                     SET t.reseller_id = c.reseller_id
                     WHERE t.reseller_id IS NULL AND c.reseller_id IS NOT NULL'
                );
            }
        },
    ],
];
