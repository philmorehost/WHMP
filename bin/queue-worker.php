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
use CodeVault\Logging\LogManager;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\Worker;

$kernel = new Kernel(dirname(__DIR__));

$kernel->boot();

/** @var QueueInterface $queue */
$queue = $kernel->container->make(QueueInterface::class);

$queueName = isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '' ? $argv[1] : 'default';

/** @var LogManager $logs */
$logs = $kernel->container->make(LogManager::class);
$logger = $logs->channel('queue');
$logs->shareContext(['queue' => $queueName]);

$worker = new Worker($queue, $queueName, logger: $logger);

fwrite(STDOUT, sprintf("[%s] queue worker started on '%s'\n", date('Y-m-d H:i:s'), $queueName));

// A worker is killed (deploy, OOM, supervisor restart) far more often than it
// exits cleanly, so record the signal rather than letting the process vanish
// with jobs in flight and nothing in the log to say why.
foreach ([SIGTERM, SIGINT] as $signal) {
    if (function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal($signal, static function (int $received) use ($logger, $queueName): void {
            $logger->warning('queue.worker_stopping', [
                'event' => 'queue.worker_stopping',
                'queue' => $queueName,
                'signal' => $received,
            ]);
            exit(0);
        });
    }
}

$logger->info('queue.worker_started', ['event' => 'queue.worker_started', 'queue' => $queueName]);

$processed = $worker->run();

$logger->info('queue.worker_stopped', [
    'event' => 'queue.worker_stopped',
    'queue' => $queueName,
    'processed' => $processed,
]);
