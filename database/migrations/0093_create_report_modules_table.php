<?php

declare(strict_types=1);

// R27 — ReportModule activation state (blueprint §3/§4.3 custom reports).
// Same shape and rationale as R20's addon_modules / R21's widget_modules
// tables: one row per report slug, JSON config blob rather than a separate
// key/value table. ReportModuleService is the only place that reads/writes
// this — like WidgetModule, a ReportModule has no activate()/deactivate()
// side effects to run on toggle, just a visibility flag controlling
// whether it's listed and runnable under Admin → Reports.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS report_modules (
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
