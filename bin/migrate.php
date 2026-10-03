<?php

declare(strict_types=1);

/**
 * Runs any pending migrations (blueprint §6/§8 — schema grows incrementally
 * per phase), then adds anything the database is still missing compared with
 * database/schema.php (SchemaReconciler). Run after deploying new code:
 *   php /path/to/WHMP/bin/migrate.php
 *
 * The website does both automatically on the first request after an upload; this
 * is for running it by hand (or from cron) and seeing the result.
 *
 *   php bin/migrate.php --check   report what is missing, change nothing
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Database\Migrator;
use CodeVault\Database\Schema\SchemaReconciler;
use CodeVault\Kernel;

$kernel = new Kernel(dirname(__DIR__));
$now = static fn (): string => date('Y-m-d H:i:s');

/** @var Migrator $migrator */
$migrator = $kernel->container->make(Migrator::class);
/** @var SchemaReconciler $reconciler */
$reconciler = $kernel->container->make(SchemaReconciler::class);

if (in_array('--check', $argv, true)) {
    $pending = $migrator->pending();
    fwrite(STDOUT, sprintf("[%s] pending migrations: %d%s\n", $now(), count($pending), $pending === [] ? '' : ' (' . implode(', ', $pending) . ')'));

    $plan = $reconciler->plan();
    if ($plan === []) {
        fwrite(STDOUT, sprintf("[%s] schema: complete — every table and column in database/schema.php exists\n", $now()));
        exit($pending === [] ? 0 : 1);
    }

    foreach ($plan as $step) {
        fwrite(STDOUT, sprintf("[%s] schema %s: %s.%s — %s\n", $now(), $step['kind'], $step['table'], $step['name'], $step['note']));
    }

    exit(1);
}

$ran = $migrator->run();

if ($ran === []) {
    fwrite(STDOUT, sprintf("[%s] no pending migrations\n", $now()));
}

foreach ($ran as $migration) {
    fwrite(STDOUT, sprintf("[%s] migrated: %s\n", $now(), $migration));
}

$result = $reconciler->reconcileIfDue($kernel->basePath('storage/system/schema-reconcile.json'), true) ?? ['applied' => [], 'failed' => [], 'reported' => []];

foreach ($result['applied'] as $applied) {
    fwrite(STDOUT, sprintf("[%s] schema repaired: %s\n", $now(), $applied));
}
foreach ($result['failed'] as $label => $error) {
    fwrite(STDERR, sprintf("[%s] schema repair FAILED: %s — %s\n", $now(), $label, $error));
}
foreach ($result['reported'] as $note) {
    fwrite(STDOUT, sprintf("[%s] schema needs attention: %s\n", $now(), $note));
}
if ($result['applied'] === [] && $result['failed'] === [] && $result['reported'] === []) {
    fwrite(STDOUT, sprintf("[%s] schema: complete\n", $now()));
}

exit($result['failed'] === [] ? 0 : 1);
