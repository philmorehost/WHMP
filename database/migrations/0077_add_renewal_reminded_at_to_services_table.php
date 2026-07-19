<?php

declare(strict_types=1);

// Lifecycle email (blueprint §4.4/§5): tracks whether a service's upcoming
// renewal has already been reminded-about this cycle, so the sweep job
// doesn't re-email the client every time it runs before the due date.

return [
    'up' => [
        'ALTER TABLE services ADD COLUMN renewal_reminded_at DATETIME NULL AFTER next_due_date',
    ],
];
