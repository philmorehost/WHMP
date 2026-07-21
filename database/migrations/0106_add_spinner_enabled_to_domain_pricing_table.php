<?php

declare(strict_types=1);

// Domain Spinner (suggests name variations to a client searching a
// domain) only checks availability across TLDs the admin has opted in
// for that feature — not every TLD offered for direct registration, since
// spinning checks many more candidate names and an admin may want to
// keep that to a handful of "major" TLDs to bound the number of live
// registrar API calls a single spin triggers.

return [
    'up' => [
        'ALTER TABLE domain_pricing ADD COLUMN spinner_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER renew_price',
    ],
];
