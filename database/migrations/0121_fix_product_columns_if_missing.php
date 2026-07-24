<?php

declare(strict_types=1);

// No-op: migration 0120 was rewritten to check INFORMATION_SCHEMA.COLUMNS
// before adding each column (portable across MySQL/MariaDB versions), so it
// now reliably creates whm_package_name, pay_type, free_duration_type, and
// free_duration_days itself. This migration's own ADD COLUMN statements
// (without any existence check) would fail with "Duplicate column" once
// 0120 succeeds, so it's kept only so the filename isn't reused.

return [
    'up' => [
        'SELECT 1',
    ],
];
