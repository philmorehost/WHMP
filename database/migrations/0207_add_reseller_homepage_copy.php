<?php

declare(strict_types=1);

// The words at the top of a store's home page.
//
// A store's "/" used to redirect to /store, which printed the WHOLE catalogue on
// one page. The storefront now has a real home page (hero, domain search, a few
// featured plans, categories under a SERVICES menu), and its headline is the
// one piece of copy a reseller will want to make their own — "Fast hosting for
// Lagos businesses" sells better than a generic line.
//
//   home_headline   the hero's main line. NULL = the built-in default.
//   home_tagline    the sentence under it. NULL = the built-in default.
//
// Both NULL for every existing store, so nothing changes until a reseller types
// something on /client/reseller/store.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            foreach ([
                'home_headline' => 'home_headline VARCHAR(120) NULL',
                'home_tagline' => 'home_tagline VARCHAR(300) NULL',
            ] as $column => $definition) {
                $exists = (string) ($db->selectOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resellers' AND COLUMN_NAME = ?",
                    [$column]
                )['COLUMN_NAME'] ?? '') !== '';

                if (!$exists) {
                    $db->statement('ALTER TABLE resellers ADD COLUMN ' . $definition);
                }
            }
        },
    ],
];
