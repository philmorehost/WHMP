<?php

declare(strict_types=1);

// Custom-domain APPROVAL — the human gate between a reseller typing a hostname
// and the server being asked to serve it.
//
// WHY THIS EXISTS
//
// Until now a reseller's custom domain went straight from "claimed" to "we will
// serve it once DNS proves control", with no point at which an administrator
// looked at the request. `resellers.domain_verification_method` could record a
// forced ('manual') verification, but nothing recorded that anyone had REVIEWED
// the request, why they refused it, or that they had refused it at all — so a
// refusal was invisible to the reseller and indistinguishable from "nothing
// happened yet".
//
// This adds the missing state, and it is deliberately separate from
// `domain_verified_at`:
//
//   domain_status        'none' | 'pending' | 'approved' | 'rejected' — the
//                        ADMIN's decision. Gates PROVISIONING (asking the web
//                        server to serve the hostname).
//   domain_verified_at   the DNS proof. Gates SERVING (whether a request that
//                        arrives for that host is answered with this store).
//
// They are kept apart on purpose. Approving a request is a statement about
// intent; proving control is a statement about fact. Collapsing them would mean
// an admin's click could serve a hostname nobody has proved they own, which is
// the one thing the verified_at test in ResellerStoreRepository exists to
// prevent. A rejected domain has its verification CLEARED, so refusal also
// stops anything already being served.
//
// `domain_review_note` carries the reason, which the reseller sees, and
// `domain_provision_error` carries whatever the hosting panel said when we tried
// to add the domain — kept separate from the review note because "an admin
// refused this" and "the panel rejected the call" are different problems with
// different owners.

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

            // Additive and guarded, one step at a time, so a re-run after a
            // partial deploy neither fails nor rewrites data.
            $columns = [
                "domain_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
                'domain_requested_at DATETIME NULL',
                'domain_reviewed_at DATETIME NULL',
                'domain_reviewed_by INT UNSIGNED NULL',
                'domain_review_note TEXT NULL',
                'domain_provisioned_at DATETIME NULL',
                'domain_provision_error TEXT NULL',
            ];

            foreach ($columns as $definition) {
                $name = strstr($definition, ' ', true);

                if (!$hasColumn($db, 'resellers', (string) $name)) {
                    $db->statement('ALTER TABLE resellers ADD COLUMN ' . $definition);
                }
            }

            $hasConstraint = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['resellers', $constraint]
                ) !== [];
            };

            // The reviewing admin, un-linked rather than deleted if the admin
            // account goes away — a review record must outlive the person.
            if (!$hasConstraint($db, 'fk_resellers_domain_reviewed_by')) {
                $db->statement(
                    'ALTER TABLE resellers ADD CONSTRAINT fk_resellers_domain_reviewed_by
                     FOREIGN KEY (domain_reviewed_by) REFERENCES admins(id) ON DELETE SET NULL'
                );
            }

            // BACKFILL, because the column defaults to 'none' and 'none' is not
            // a reachable state for a claim that already exists: it would be
            // invisible in the review queue AND unapprovable (approveDomain() is
            // guarded on 'pending'), which reads as "the button does nothing".
            //
            // A domain that is already VERIFIED was accepted in fact — we serve
            // it — so record that as the decision it was, rather than inventing a
            // queue item for something already working. A claim with no proof is
            // genuinely undecided, so it becomes a pending request.
            $db->statement(
                "UPDATE resellers SET domain_status = 'approved',
                        domain_reviewed_at = COALESCE(domain_reviewed_at, domain_verified_at)
                 WHERE domain_verified_at IS NOT NULL AND domain_status = 'none'"
            );

            $db->statement(
                "UPDATE resellers SET domain_status = 'pending',
                        domain_requested_at = COALESCE(domain_requested_at, updated_at)
                 WHERE domain_verified_at IS NULL
                   AND custom_domain IS NOT NULL AND custom_domain <> ''
                   AND domain_status = 'none'"
            );
        },
    ],
];
