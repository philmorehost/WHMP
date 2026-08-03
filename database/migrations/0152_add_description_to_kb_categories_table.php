<?php

declare(strict_types=1);

// KB categories only had name + sort_order — no field for the AI copilot (or
// an admin) to describe what belongs in a category. Nullable so every
// existing category keeps working without a value.

return [
    'up' => [
        <<<'SQL'
        ALTER TABLE kb_categories
            ADD COLUMN description TEXT NULL AFTER name
        SQL,
    ],
];
