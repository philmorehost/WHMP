<?php

declare(strict_types=1);

// Renames license_activation -> system_activation as part of scrubbing
// "license" from every traceable identifier in the codebase (class names,
// namespace, storage paths, and now the table itself). Migration 0003
// stays untouched — its filename and content are part of already-applied
// migration history on every existing install — this is a new, additive
// rename on top of it rather than an edit to it.

return [
    'up' => [
        'RENAME TABLE license_activation TO system_activation',
    ],
];
