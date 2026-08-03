<?php

declare(strict_types=1);

// Service lifecycle automation: how long an expired service survives before
// suspension and termination, and how long an overdue invoice waits before a
// late fee is applied.
//
// Termination grace splits by product type because the cost profile does:
// a VPS or dedicated box holds real hardware/allocation, so it reclaims after
// a day, while shared hosting can sit dormant for weeks at negligible cost.
//
// The two *_enabled switches ship OFF. Suspending and especially terminating
// a service are customer-visible and, for termination, irreversible — the
// account and its data are destroyed on the remote server. Turning that on
// must be a deliberate act by the admin on an install that already has live
// services, not something a deploy silently starts doing. The day counts are
// pre-set to the intended values so enabling is a single click.
//
// INSERT IGNORE throughout so the automatic on-boot migrator can re-apply.

return [
    'up' => [
        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES
            ('billing.late_fee_grace_days', '0', NOW()),
            ('billing.auto_suspend_enabled', '0', NOW()),
            ('billing.suspension_grace_days', '7', NOW()),
            ('billing.auto_terminate_enabled', '0', NOW()),
            ('billing.termination_grace_days', '60', NOW()),
            ('billing.termination_grace_days_server', '1', NOW())
        SQL,
    ],
];
