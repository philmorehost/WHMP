<?php

declare(strict_types=1);

/**
 * Regenerates database/schema.php from database/migrations.
 *
 *   php bin/build-schema.php          write the snapshot
 *   php bin/build-schema.php --check  exit 1 if the snapshot is out of date
 *
 * Run it whenever a migration is added or changed, and commit the result. No database
 * is needed: the migrations are replayed against an in-memory model of the schema.
 * Any statement a real server would reject (a missing table, a duplicate column, a
 * foreign key with mismatched types) is printed and the exit code is 1.
 */

$base = dirname(__DIR__);

if (is_file($base . '/vendor/autoload.php')) {
    require $base . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($base): void {
        if (str_starts_with($class, 'CodeVault\\')) {
            $file = $base . '/core/' . str_replace('\\', '/', substr($class, strlen('CodeVault\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require $base . '/core/helpers.php';
}

use CodeVault\Database\Schema\SchemaSnapshot;

$result = (new SchemaSnapshot($base . '/database/migrations'))->build();
$target = $base . '/database/schema.php';

foreach ($result['errors'] as $error) {
    fwrite(STDERR, "migration problem: {$error}\n");
}

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';

    if ($current !== $result['source']) {
        fwrite(STDERR, "database/schema.php is out of date — run: php bin/build-schema.php\n");
        exit(1);
    }

    fwrite(STDOUT, "database/schema.php is up to date\n");
    exit($result['errors'] === [] ? 0 : 1);
}

file_put_contents($target, $result['source']);
fwrite(STDOUT, 'wrote database/schema.php (' . $result['tables'] . " tables)\n");

exit($result['errors'] === [] ? 0 : 1);
