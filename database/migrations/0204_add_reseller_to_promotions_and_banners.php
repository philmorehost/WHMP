<?php

declare(strict_types=1);

// Promo codes and promo banners become TENANT-SCOPED.
//
// THE BUG THIS FIXES
//
// `promo_banners` had no owner column, and `active_promo_banner()` asked for "the
// newest active banner for this page" with no idea which site was being served. A
// reseller's storefront renders through the same `layouts.client`, so the platform's
// own discount popup appeared on every reseller's website — advertising OUR coupon,
// inside somebody else's white-label shop. The same was true of `promotions`: a code
// the platform created for its own customers was honoured on every storefront.
//
// THE RULE
//
//   reseller_id IS NULL   -> the platform's own banner / code. Shown and honoured on
//                            the platform's site only.
//   reseller_id = <store> -> that store's banner / code. Shown and honoured on that
//                            store's site only, managed by that reseller only.
//
// There is no fallback in either direction: a store with no banner shows no banner
// (not ours), and a code is only valid on the site that owns it.
//
// WHY THE UNIQUE KEY ON promotions.code BECOMES (reseller_id, code)
//
// Two unrelated stores must both be able to run "SAVE10" — refusing the second one
// with "that code is taken" would leak that another store exists and is using it,
// which is exactly the cross-tenant visibility this change exists to remove.
// MySQL/MariaDB treat NULLs as distinct inside a UNIQUE key, so platform codes are
// kept unique by PromotionRepository::save(), which has always upserted by code.
//
// ON DELETE CASCADE for both: a banner or a code is the store's marketing, and it has
// no meaning once the store is gone. Orders keep their own copy of the discount they
// were charged, so no money record depends on the promotion row surviving.

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

            $hasConstraint = static function (\CodeVault\Database $db, string $table, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [$table, $constraint]
                ) !== [];
            };

            // ---------------------------------------------------- promo_banners
            if (!$hasColumn($db, 'promo_banners', 'reseller_id')) {
                $db->statement('ALTER TABLE promo_banners ADD COLUMN reseller_id INT UNSIGNED NULL AFTER id');
            }

            if (!$hasIndex($db, 'promo_banners', 'idx_promo_banners_reseller')) {
                $db->statement('ALTER TABLE promo_banners ADD INDEX idx_promo_banners_reseller (reseller_id, status)');
            }

            if (!$hasConstraint($db, 'promo_banners', 'fk_promo_banners_reseller')) {
                $db->statement(
                    'ALTER TABLE promo_banners ADD CONSTRAINT fk_promo_banners_reseller
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }

            // ------------------------------------------------------- promotions
            if (!$hasColumn($db, 'promotions', 'reseller_id')) {
                $db->statement('ALTER TABLE promotions ADD COLUMN reseller_id INT UNSIGNED NULL AFTER id');
            }

            // Drop every single-column UNIQUE index on `code` (MySQL names the one
            // created by `code ... UNIQUE` after the column, but an imported schema
            // may have named it anything), then add the per-tenant one.
            $uniqueOnCode = $db->select(
                "SELECT INDEX_NAME, COUNT(*) AS cols, MAX(COLUMN_NAME) AS col
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND NON_UNIQUE = 0
                   AND INDEX_NAME <> 'PRIMARY'
                 GROUP BY INDEX_NAME"
            );

            foreach ($uniqueOnCode as $index) {
                if ((int) $index['cols'] === 1 && strtolower((string) $index['col']) === 'code') {
                    $db->statement('ALTER TABLE promotions DROP INDEX `' . str_replace('`', '', (string) $index['INDEX_NAME']) . '`');
                }
            }

            if (!$hasIndex($db, 'promotions', 'uniq_promotions_reseller_code')) {
                $db->statement('ALTER TABLE promotions ADD UNIQUE KEY uniq_promotions_reseller_code (reseller_id, code)');
            }

            // Lookups are "this code, on this site" — code first is the useful order
            // for that, and the unique key above leads with reseller_id.
            if (!$hasIndex($db, 'promotions', 'idx_promotions_code')) {
                $db->statement('ALTER TABLE promotions ADD INDEX idx_promotions_code (code)');
            }

            if (!$hasConstraint($db, 'promotions', 'fk_promotions_reseller')) {
                $db->statement(
                    'ALTER TABLE promotions ADD CONSTRAINT fk_promotions_reseller
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }
        },
    ],
];
