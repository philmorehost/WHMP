<?php

declare(strict_types=1);

/**
 * Queue worker CLI entry point (blueprint §3 — "Queue/worker"). Run as a
 * long-lived supervised process, e.g. under systemd or supervisord:
 *   php /path/to/WHMP/bin/queue-worker.php          # 'default' queue
 *   php /path/to/WHMP/bin/queue-worker.php email    # outbound email queue
 *
 * A worker polls one queue, so run one process per queue you want drained
 * (the order-acceptance job lives on 'default'; SendEmailJob rides 'email').
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Kernel;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\Worker;

$kernel = new Kernel(dirname(__DIR__));

/** @var QueueInterface $queue */
$queue = $kernel->container->make(QueueInterface::class);

$queueName = isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '' ? $argv[1] : 'default';

$worker = new Worker($queue, $queueName);

fwrite(STDOUT, sprintf("[%s] queue worker started on '%s'\n", date('Y-m-d H:i:s'), $queueName));

$worker->run();
