<?php

declare(strict_types=1);

// The address a store's customers are written to FROM.
//
// WHY THIS IS NOT COSMETIC
//
// Branding the display NAME of an email is free — a name is decoration and cannot
// make a message undeliverable. An ADDRESS is different: sending as
// `support@their-domain` from our server, when that domain has not authorised us in
// SPF and DKIM, fails DMARC alignment at the receiving end. The mail lands in spam
// or bounces outright, and nothing in this application would ever say so — the
// reseller would just be quietly losing customers' support replies.
//
// So the address is stored here, and the STATE OF THE CHECK IS STORED WITH IT:
//
//   support_email              the address itself. NULL/'', not set.
//   support_email_status       'aligned' | 'misaligned' | NULL (never checked).
//   support_email_checked_at   WHEN it was checked. Stored even on a pass, because a
//                              pass is not permanent truth — DNS records get edited
//                              and domains get re-pointed, and a result with no date
//                              on it reads as current forever.
//   support_email_detail       What to publish, verbatim, when it is misaligned:
//                              the exact SPF and DKIM records the reseller must add.
//                              A warning without the fix is only half a warning.
//
// The check is deliberately NOT a gate on sending. DNS can be right when our lookup
// fails (a resolver outage, a provider that rate-limits), and refusing to send as the
// store in that case would silently revert to the PLATFORM address — reintroducing
// the exact white-label leak this feature exists to close, with nobody told. So a set
// address is used, and the portal carries a loud, dated warning while it is unsafe.
//
// No backfill: every existing store has no address of its own, which is already the
// correct starting state (the configured platform sender carries the message, with
// the store's name on it — see ResellerMailIdentity).

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
                // 190 matches the length this codebase already uses for stored addresses,
                // so the column can carry an indexed unique email without truncation.
                'support_email VARCHAR(190) NULL',
                'support_email_status VARCHAR(20) NULL',
                'support_email_checked_at DATETIME NULL',
                'support_email_detail TEXT NULL',
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
