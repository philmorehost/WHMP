<?php

declare(strict_types=1);

// WHICH hostname is actually on the hosting panel.
//
// WHY A SECOND DOMAIN COLUMN
//
// `custom_domain` is what the store is ALLOWED to be served on. It is
// overwritten the moment a reseller types a different name, so it cannot also
// answer "what did we put on the panel?". Those are two facts that come apart
// exactly when a reseller changes their domain, and until now only one of them
// was recorded:
//
//   - `domain_provisioned_at` said that we had, at some point, successfully
//     asked the panel to add *whatever the domain was then*;
//   - `setCustomDomain()` then CLEARED it, and overwrote `custom_domain`.
//
// So a reseller moving from shop.old.com to shop.new.com destroyed the only
// record that shop.old.com was on the panel. Nothing could remove it, and an
// addon domain we added ourselves would sit on the server for good, still
// answering for a hostname that no longer matches any store — which resolves to
// the PLATFORM shop at PLATFORM prices rather than 404. That is the same
// tenant-isolation failure the apex redirect was written to close, except that
// this one is a hostname we added on purpose.
//
// `domain_provisioned_host` is therefore the panel's own state, kept separate
// from the claim and deliberately NOT cleared when the claim changes:
//
//   domain_provisioned_host = NULL          nothing of ours is on the panel
//   ... = custom_domain                     in sync
//   ... <> custom_domain                    the old name must come OFF
//
// Its companion `domain_provisioned_at` answers "when did that host go on", and
// the two are written and cleared together. Removal is driven by the pair, so
// clearing the timestamp without the host (or the reverse) would leave a
// hostname permanently un-removable.
//
// BACKFILL. A row that recorded a successful provisioning has been in sync ever
// since, because the only thing that could have desynchronised it
// (`setCustomDomain`) also cleared the timestamp. So the current claim IS the
// provisioned host for those rows, and reconstructing it is a statement of fact
// rather than a guess.
//
// Nothing here changes what is SERVED. Serving still requires
// `domain_verified_at` (see migration 0198); this column only ever decides what
// we ask the hosting panel to do.

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

            if (!$hasColumn($db, 'resellers', 'domain_provisioned_host')) {
                // No `AFTER` clause: on an install where migration 0198 did not
                // complete, the column it would be positioned against is absent
                // and the statement would fail for a reason that has nothing to
                // do with this one.
                $db->statement('ALTER TABLE resellers ADD COLUMN domain_provisioned_host VARCHAR(255) NULL');
            }

            // Only meaningful when the approval column exists at all: without
            // 0198 there is no domain_provisioned_at to reason from.
            if ($hasColumn($db, 'resellers', 'domain_provisioned_at')) {
                $db->statement(
                    "UPDATE resellers SET domain_provisioned_host = custom_domain
                     WHERE domain_provisioned_at IS NOT NULL
                       AND custom_domain IS NOT NULL
                       AND custom_domain <> ''
                       AND domain_provisioned_host IS NULL"
                );
            }
        },
    ],
];
