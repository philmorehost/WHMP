<?php

declare(strict_types=1);

// R24 — NotificationModule SDK fix. The `type` column was a hard ENUM
// ('slack', 'webhook') tied to two hardcoded providers, while a separate
// core/Modules/NotificationModule.php interface sat unused since R0 (never
// wired into ModuleManager's registered types, never called by
// NotificationDispatcher). Widened to VARCHAR so any registered
// NotificationModule's slug (e.g. 'discord') can be used as a valid
// endpoint type going forward without a migration every time a new
// notification module ships — existing 'slack'/'webhook' rows are valid
// VARCHAR values already, so this is a lossless widen.

return [
    'up' => [
        "ALTER TABLE notification_endpoints MODIFY COLUMN type VARCHAR(32) NOT NULL",
    ],
];
