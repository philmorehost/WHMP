<?php

declare(strict_types=1);

// Redundant safety net for deployments where the document root is this
// repo root (see the accompanying .htaccess) and, for whatever reason,
// mod_rewrite/AllowOverride isn't active on the host — Apache's
// DirectoryIndex still finds this file for a bare "/" request even
// without rewrite support, so the app boots (installer, then the real
// landing page once installed) instead of falling through to a directory
// listing. This alone does NOT make deep routes like /login work without
// rewrite — the .htaccess is the real, complete fix; this is a fallback
// that at minimum keeps the homepage safe and functional.
//
// __DIR__ inside the required file always reflects public/index.php's own
// location on disk, not this file's, so every path it resolves (vendor/
// autoload, storage/, etc.) is correct regardless of being required from here.
require __DIR__ . '/public/index.php';
