<?php

declare(strict_types=1);

// R20 — AddonModule activation state (blueprint §3/§7 Module SDK). The
// AddonModule interface (core/Modules/AddonModule.php) existed since R0 but
// nothing ever persisted which addons an admin has actually turned on, so
// ModuleManager::register() wired every registered addon's hooks()
// unconditionally at boot regardless of activation — a real addon lifecycle
// needs that gated by admin action instead. One row per addon slug; config
// stored as a JSON blob rather than a separate key/value table since no
// addon needs more than a handful of settings (mirrors ThemeSettings'/
// GdprSettings' single-blob pattern, not the finer-grained per-row pattern
// used where a table genuinely needs many rows, like custom fields).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS addon_modules (
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
