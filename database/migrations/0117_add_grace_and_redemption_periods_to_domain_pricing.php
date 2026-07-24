<?php

declare(strict_types=1);

// Removed: These columns are now created directly in migration 0097.
// This migration is kept for historical compatibility but is now empty.
// For legacy installations upgrading from an older 0097, the columns
// were already backfilled when 0097 was retroactively updated.

return [
    'up' => [
        // No-op: columns already created by migration 0097
        "SELECT 1",
    ],
];
