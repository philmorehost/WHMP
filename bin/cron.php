<?php

declare(strict_types=1);

/**
 * The single system cron entry point (blueprint §3). Point exactly one
 * real OS cron entry at this file, e.g.:
 *   * * * * * php /path/to/WHMP/bin/cron.php >> storage/cron.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Backup\BackupCronJob;
use CodeVault\Billing\AutoChargeJob;
use CodeVault\Billing\BillableItemInvoicingJob;
use CodeVault\Billing\DunningJob;
use CodeVault\Billing\QuoteExpiryJob;
use CodeVault\Billing\RecurringBillingJob;
use CodeVault\Billing\RecurringBillingService;
use CodeVault\Billing\RenewalReminderJob;
use CodeVault\Cron\CronScheduler;
use CodeVault\Domains\DomainRenewalBillingJob;
use CodeVault\Domains\DomainSyncJob;
use CodeVault\Gdpr\DataPruningJob;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Kernel;
use CodeVault\Integrity\IntegrityCheckJob;
use CodeVault\Integrity\IntegrityManager;
use CodeVault\Support\MailPipingJob;
use CodeVault\Support\TicketAutoCloseJob;
use CodeVault\Support\TicketEscalationJob;
use CodeVault\Billing\CancellationCronJob;

$kernel = new Kernel(dirname(__DIR__));

/** @var CronScheduler $scheduler */
$scheduler = $kernel->container->make(CronScheduler::class);

/** @var HookDispatcher $hooks */
$hooks = $kernel->container->make(HookDispatcher::class);

// Real job registration (invoicing, suspensions, domain sync, ...) is
// wired here as each engine lands.
if (is_file($kernel->basePath('.installed.lock'))) {
    $scheduler->register(new IntegrityCheckJob($kernel->container->make(IntegrityManager::class)));
    $scheduler->register(new RecurringBillingJob($kernel->container->make(RecurringBillingService::class)));
    // Auto-charge runs before dunning so successfully-charged invoices are
    // already paid and never trigger an overdue notice.
    $scheduler->register($kernel->container->make(AutoChargeJob::class));
    $scheduler->register($kernel->container->make(DunningJob::class));
    $scheduler->register($kernel->container->make(DomainRenewalBillingJob::class));
    $scheduler->register($kernel->container->make(DomainSyncJob::class));
    $scheduler->register($kernel->container->make(TicketEscalationJob::class));
    $scheduler->register($kernel->container->make(TicketAutoCloseJob::class));
    $scheduler->register($kernel->container->make(BillableItemInvoicingJob::class));
    $scheduler->register($kernel->container->make(MailPipingJob::class));
    $scheduler->register($kernel->container->make(BackupCronJob::class));
    $scheduler->register($kernel->container->make(CancellationCronJob::class));
    $scheduler->register($kernel->container->make(RenewalReminderJob::class));
    $scheduler->register($kernel->container->make(DataPruningJob::class));
    $scheduler->register($kernel->container->make(QuoteExpiryJob::class));
}

$results = $scheduler->run($hooks);

$out = defined('STDOUT') ? STDOUT : fopen('php://output', 'w');

foreach ($results as $job => $result) {
    $status = $result['ran'] ? 'ran' : (isset($result['error']) ? 'failed: ' . $result['error'] : 'skipped (not due)');
    fwrite($out, sprintf("[%s] %-30s %s\n", date('Y-m-d H:i:s'), $job, $status));
}

if ($results === []) {
    fwrite($out, sprintf("[%s] no jobs registered\n", date('Y-m-d H:i:s')));
}
