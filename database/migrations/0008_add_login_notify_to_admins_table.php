<?php

declare(strict_types=1);

// Per-user login-notify toggle (blueprint §5).

return [
    'up' => [
        'ALTER TABLE admins ADD COLUMN login_notify_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER display_name',
    ],
];
