<?php

declare(strict_types=1);

// R22 — VAT number + reverse-charge support (blueprint §4.4 Tax/VAT
// engine: "VAT number validation, format + live lookup e.g. VIES-style,
// with reverse-charge/exemption handling"). vat_number is admin/client
// editable; the vat_verified_* columns record the outcome of an on-demand
// live EU VIES lookup separately, so "the client typed a number" and "we
// confirmed it against VIES" are never conflated — format-valid alone is
// enough to trigger reverse-charge at checkout (mirrors how real billing
// systems accept a format-valid number immediately and verify async),
// while vat_verified_* is the audit trail for compliance review.

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN vat_number VARCHAR(32) NULL AFTER country',
        'ALTER TABLE clients ADD COLUMN vat_verified_at DATETIME NULL AFTER vat_number',
        'ALTER TABLE clients ADD COLUMN vat_verified_valid TINYINT(1) NULL AFTER vat_verified_at',
        'ALTER TABLE clients ADD COLUMN vat_verified_name VARCHAR(255) NULL AFTER vat_verified_valid',
    ],
];
