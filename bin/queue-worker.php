<?php

declare(strict_types=1);

/**
 * Queue worker CLI entry point (blueprint §3 — "Queue/worker"). Run as a
 * long-lived supervised process, e.g. under systemd or supervisord:
 *   php /path/to/WHMP/bin/queue-worker.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Kernel;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\Worker;

$kernel = new Kernel(dirname(__DIR__));

/** @var QueueInterface $queue */
$queue = $kernel->container->make(QueueInterface::class);

$worker = new Worker($queue, 'default');

fwrite(STDOUT, sprintf("[%s] queue worker started\n", date('Y-m-d H:i:s')));

$worker->run();
