<?php

declare(strict_types=1);

/**
 * Runs any pending migrations (blueprint §6/§8 — schema grows incrementally
 * per phase). Run after deploying new code that added migration files:
 *   php /path/to/WHMP/bin/migrate.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Database\Migrator;
use CodeVault\Kernel;

$kernel = new Kernel(dirname(__DIR__));

/** @var Migrator $migrator */
$migrator = $kernel->container->make(Migrator::class);

$ran = $migrator->run();

if ($ran === []) {
    fwrite(STDOUT, sprintf("[%s] no pending migrations\n", date('Y-m-d H:i:s')));

    exit(0);
}

foreach ($ran as $migration) {
    fwrite(STDOUT, sprintf("[%s] migrated: %s\n", date('Y-m-d H:i:s'), $migration));
}
