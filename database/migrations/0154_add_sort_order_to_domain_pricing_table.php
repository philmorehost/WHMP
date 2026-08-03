<?php

declare(strict_types=1);

// TLD tabs/lists were strictly alphabetical with no admin control over
// display order. Nullable-free — 0 is a fine default (new TLDs just sort to
// the front alongside every other unset row until an admin numbers them).

return [
    'up' => [
        'ALTER TABLE domain_pricing ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER category',
    ],
];
