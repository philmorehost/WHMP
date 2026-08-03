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
use CodeVault\Cron\CronActivityReportJob;
use CodeVault\Cron\CronRunRepository;
use CodeVault\Cron\CronStateRepository;
use CodeVault\Cron\CronScheduler;
use CodeVault\Settings\SettingsRepository;
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
use CodeVault\Billing\OverdueSuspensionJob;
use CodeVault\Marketing\CampaignDispatchJob;
use CodeVault\Billing\ServiceTerminationJob;
use CodeVault\Billing\ServicePruningJob;
use CodeVault\Domains\DomainPruningJob;
use CodeVault\Billing\StaleInvoiceCancellationJob;

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

    // Service lifecycle. Suspension runs before termination so a service
    // that becomes due for both in the same tick is suspended first, and
    // both are hourly so a one-day server grace reclaims at the top of the
    // hour rather than waiting for the next daily sweep.
    $scheduler->register($kernel->container->make(OverdueSuspensionJob::class));
    $scheduler->register($kernel->container->make(ServiceTerminationJob::class));
    $scheduler->register($kernel->container->make(StaleInvoiceCancellationJob::class));

    // Runs daily, well after termination has had a chance to act — deletes
    // services/domains that have been dead long enough that nothing will
    // ever reference them again. Both off by default.
    $scheduler->register($kernel->container->make(ServicePruningJob::class));
    $scheduler->register($kernel->container->make(DomainPruningJob::class));

    // Drains queued campaign emails a few per minute so a large campaign
    // never floods the mail host in one burst.
    $scheduler->register($kernel->container->make(CampaignDispatchJob::class));

    // Registered last so its 24h window already includes everything the jobs
    // above just recorded on this tick.
    $scheduler->register($kernel->container->make(CronActivityReportJob::class));

    // Reporting sink + the admin's configured daily automation time. Both are
    // attached here rather than injected, so the scheduler still works on a
    // system whose schema/settings aren't ready yet.
    $scheduler->recordRunsTo($kernel->container->make(CronRunRepository::class));
    // Durable last-run state. Without this the scheduler relies on a JSON
    // file whose write was never checked — on a host with an unwritable
    // storage/ directory every job re-ran on every tick.
    $scheduler->persistStateTo($kernel->container->make(CronStateRepository::class));
    $scheduler->useDailyRunTime(static function () use ($kernel): ?string {
        return $kernel->container->make(SettingsRepository::class)->get('automation.daily_run_time', '00:05');
    });
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
