<?php

declare(strict_types=1);

// R21 — WidgetModule activation state (blueprint §3/§4.3 dashboard widgets).
// Same shape and rationale as R20's addon_modules table: one row per widget
// slug, JSON config blob rather than a separate key/value table since no
// widget needs more than a handful of settings. WidgetModuleService is the
// only place that reads/writes this — WidgetModule itself has no
// activate()/deactivate() lifecycle methods (unlike AddonModule), since a
// dashboard widget has no side effects to run on toggle, just a visibility
// flag.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS widget_modules (
            slug VARCHAR(64) NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            config TEXT NULL,
            activated_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
