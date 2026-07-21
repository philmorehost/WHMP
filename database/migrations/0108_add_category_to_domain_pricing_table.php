<?php

declare(strict_types=1);

// TLD categories power the tabbed browse-by-category UI on the public
// domain registration page (Popular / Geographic / Technology / Shopping /
// Novelty / Other, mirroring WHMCS). Free-text so an admin can invent their
// own tabs; defaults to 'Popular' so existing TLDs show up without setup.

return [
    'up' => [
        "ALTER TABLE domain_pricing ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'Popular' AFTER tld",
        "UPDATE domain_pricing SET category = 'Popular' WHERE tld IN ('.com', '.net', '.org')",
        "UPDATE domain_pricing SET category = 'Geographic' WHERE tld IN ('.com.ng', '.ng')",
    ],
];
