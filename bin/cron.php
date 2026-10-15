<?php

declare(strict_types=1);

/**
 * The single system cron entry point (blueprint §3). Point exactly one
 * real OS cron entry at this file, e.g.:
 *   * * * * * php /path/to/WHMP/bin/cron.php >> storage/cron.log 2>&1
 *
 * Resource guarantees (why one every-minute entry is safe to leave running):
 *   - Overlap lock: a non-blocking flock on storage/cache/cron.lock makes a
 *     tick that starts while the previous one is still working exit
 *     immediately. Without it, one slow job (a backup, a big domain sync)
 *     used to stack up one fresh copy of the whole cron per minute until the
 *     host ran out of CPU and memory.
 *   - Lazy jobs: every job below is registered as name + cadence + factory.
 *     A job's object graph is only built on a tick where it is actually due,
 *     so the ~1,440 mostly-idle ticks a day construct almost nothing.
 *   - Silent idle ticks: when nothing runs, nothing is written — no state
 *     upserts, no log lines. Failed jobs are ALWAYS logged.
 *   - Set CRON_VERBOSE=1 in .env to log "not due" lines again while
 *     debugging scheduling.
 *
 * Still exactly one OS cron entry, please: the lock stops overlapping ticks,
 * not duplicate schedules (two cron entries both due at :00 would still run
 * everything twice).
 */

$lockFile = dirname(__DIR__) . '/storage/cache/cron.lock';

if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}

$lockHandle = @fopen($lockFile, 'c');

if ($lockHandle === false) {
    // Unwritable storage/: run unlocked rather than not at all — the
    // scheduler's DB-backed state does not depend on this file.
    error_log("cron.php: cannot open {$lockFile} — running without the overlap lock.");
} elseif (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $out = defined('STDOUT') ? STDOUT : fopen('php://output', 'w');
    fwrite($out, sprintf("[%s] previous cron run still active — skipping this tick\n", date('Y-m-d H:i:s')));
    exit(0);
}

// The lock is held for the lifetime of this process; the kernel (and the
// autoloader it needs) is only booted once a tick owns the cron.
require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Ai\AiSystemHealthJob;
use CodeVault\Backup\BackupCronJob;
use CodeVault\Cart\AbandonedCartJob;
use CodeVault\Config;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\RedisQueue;
use CodeVault\Queue\Worker;
use CodeVault\Billing\AutoChargeJob;
use CodeVault\Billing\BillableItemInvoicingJob;
use CodeVault\Billing\DunningJob;
use CodeVault\Billing\QuoteExpiryJob;
use CodeVault\Billing\RecurringBillingJob;
use CodeVault\Billing\RecurringBillingService;
use CodeVault\Billing\RecurringInvoiceJob;
use CodeVault\Billing\RecurringInvoiceService;
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
use CodeVault\Reseller\ResellerCostBillingJob;
use CodeVault\Reseller\DomainVerificationJob;
use CodeVault\Reseller\ResellerMailboxJob;

$kernel = new Kernel(dirname(__DIR__));

/** @var CronScheduler $scheduler */
$scheduler = $kernel->container->make(CronScheduler::class);

/** @var HookDispatcher $hooks */
$hooks = $kernel->container->make(HookDispatcher::class);

$c = $kernel->container;

// Real job registration (invoicing, suspensions, domain sync, ...) is
// wired here as each engine lands. registerLazy() defers construction:
// the container only builds a job (and everything it needs) on a tick
// where that job is due. Names/cadences mirror the job classes; the
// scheduler re-checks the real object before running anything, so a
// stale entry here can delay a run by a tick but never skip or double it.
if (is_file($kernel->basePath('.installed.lock'))) {
    $scheduler->registerLazy('system.integrity-check', 360, fn () => $c->make(IntegrityCheckJob::class));
    $scheduler->registerLazy('recurring-billing', 1440, fn () => $c->make(RecurringBillingJob::class));
    // Standalone recurring invoices (admin "make this invoice recur") —
    // runs after the service/domain renewal sweep, once a day.
    $scheduler->registerLazy('recurring-invoices', 1440, fn () => $c->make(RecurringInvoiceJob::class));
    // Auto-charge runs before dunning so successfully-charged invoices are
    // already paid and never trigger an overdue notice.
    $scheduler->registerLazy('auto-charge', 1440, fn () => $c->make(AutoChargeJob::class));
    $scheduler->registerLazy('dunning', 1440, fn () => $c->make(DunningJob::class));
    $scheduler->registerLazy('domain-renewal-billing', 1440, fn () => $c->make(DomainRenewalBillingJob::class));
    $scheduler->registerLazy('domain-sync', 1440, fn () => $c->make(DomainSyncJob::class));
    $scheduler->registerLazy('ticket-escalation', 15, fn () => $c->make(TicketEscalationJob::class));
    $scheduler->registerLazy('ticket-auto-close', 1440, fn () => $c->make(TicketAutoCloseJob::class));
    $scheduler->registerLazy('billable-item-invoicing', 1440, fn () => $c->make(BillableItemInvoicingJob::class));
    // Bills each store for the cost of the orders it took, one invoice per
    // closed calendar month. Registered after the renewal sweep so an order
    // written by this tick's work is billed on the next one, never midway.
    $scheduler->registerLazy('reseller-cost-billing', 1440, fn () => $c->make(ResellerCostBillingJob::class));
    $scheduler->registerLazy('mail-piping', 5, fn () => $c->make(MailPipingJob::class));
    // A claimed store domain goes live on its own once DNS answers, instead of
    // waiting for the reseller to press "Verify" again. Verifies only; it never
    // un-verifies, so a transient DNS failure cannot take a store offline.
    $scheduler->registerLazy('reseller-domain-verification', 1440, fn () => $c->make(DomainVerificationJob::class));
    // Registered on its own schedule rather than folded into verification, because a store can
    // become eligible for a mailbox in ways verification does not cover: the domain was approved
    // before provisioning was switched on, an admin added it by hand, or a panel call failed and
    // needs retrying. The job asks "who is eligible?" instead, which handles all of them and is
    // safe to run repeatedly — a record of which domain a mailbox was made for is what stops it
    // doing the same store twice.
    $scheduler->registerLazy('reseller-mailbox-provisioning', 1440, fn () => $c->make(ResellerMailboxJob::class));

    // The one eager registration: daily-backup's cadence is settings-driven
    // (backup.frequency_hours, floored at one hour), so the scheduler must
    // ask the real job for its frequencyMinutes() on every gate instead of
    // guessing a constant here. Construction is cheap; handle() only runs
    // when the job is due.
    $scheduler->register($c->make(BackupCronJob::class));

    $scheduler->registerLazy('cancellation-processor', 60, fn () => $c->make(CancellationCronJob::class));
    $scheduler->registerLazy('service-renewal-reminder', 1440, fn () => $c->make(RenewalReminderJob::class));
    $scheduler->registerLazy('gdpr-data-pruning', 1440, fn () => $c->make(DataPruningJob::class));
    $scheduler->registerLazy('quote-expiry', 1440, fn () => $c->make(QuoteExpiryJob::class));
    // Cart abandonment recovery — hourly so the idle threshold (default
    // 2h) resolves within a reasonable window of a visitor leaving.
    $scheduler->registerLazy('abandoned-cart', 60, fn () => $c->make(AbandonedCartJob::class));

    // Service lifecycle. Suspension runs before termination so a service
    // that becomes due for both in the same tick is suspended first, and
    // both are hourly so a one-day server grace reclaims at the top of the
    // hour rather than waiting for the next daily sweep.
    $scheduler->registerLazy('overdue-suspension', 60, fn () => $c->make(OverdueSuspensionJob::class));
    $scheduler->registerLazy('service-termination', 60, fn () => $c->make(ServiceTerminationJob::class));
    $scheduler->registerLazy('stale-invoice-cancellation', 1440, fn () => $c->make(StaleInvoiceCancellationJob::class));

    // Runs daily, well after termination has had a chance to act — deletes
    // services/domains that have been dead long enough that nothing will
    // ever reference them again. Both off by default.
    $scheduler->registerLazy('service-pruning', 1440, fn () => $c->make(ServicePruningJob::class));
    $scheduler->registerLazy('domain-pruning', 1440, fn () => $c->make(DomainPruningJob::class));

    // cPanel Username Changer: runs queued renames, expires unconfirmed
    // requests, reconciles fee invoices and refreshes the per-server account
    // copy that keeps the live username check instant. A no-op while the add-on
    // is inactive.
    $scheduler->registerLazy('username-changer', 5, fn () => $c->make(\CodeVault\UsernameChanger\UsernameChangeCronJob::class));

    // Invalid Email Blocker: re-runs the Email Validation scan every N days (addon
    // setting) so fixed addresses are un-blocked and newly dead ones caught. A no-op
    // while the addon is inactive or its re-scan is set to 0.
    $scheduler->registerLazy('email-validation-rescan', 60, fn () => $c->make(\CodeVault\Clients\EmailValidationRescanJob::class));

    // Cloudflare (free): deletes zones whose grace period ended, checks pending
    // zones for activation (with day-3/day-7 reminders) and reconciles live zones
    // with their services daily. A no-op while the add-on is inactive.
    $scheduler->registerLazy('cloudflare', 15, fn () => $c->make(\CodeVault\Cloudflare\CloudflareCronJob::class));

    // Drains queued campaign emails a few per minute so a large campaign
    // never floods the mail host in one burst.
    $scheduler->registerLazy('campaign-dispatch', 1, fn () => $c->make(CampaignDispatchJob::class));

    // Registered last so its 24h window already includes everything the jobs
    // above just recorded on this tick.
    $scheduler->registerLazy('cron_activity_report', 1440, fn () => $c->make(CronActivityReportJob::class));

    // Weekly AI-assisted system health scan: gathers cron + PHP errors from
    // the last 7 days, has the AI analyse them and propose an implementation
    // plan, and emails the admin. Fails open to the raw error log if the AI
    // key is missing. (Registered once — it used to be registered twice,
    // doubling the AI call and the admin email whenever the scan errored.)
    $scheduler->registerLazy('ai_system_health', 10080, fn () => $c->make(AiSystemHealthJob::class));

    // Reporting sink + the admin's configured daily automation time. Both are
    // attached here rather than injected, so the scheduler still works on a
    // system whose schema/settings aren't ready yet.
    $scheduler->recordRunsTo($c->make(CronRunRepository::class));
    // Durable last-run state. Without this the scheduler relies on a JSON
    // file whose write was never checked — on a host with an unwritable
    // storage/ directory every job re-ran on every tick.
    $scheduler->persistStateTo($c->make(CronStateRepository::class));
    $scheduler->useDailyRunTime(static function () use ($c): ?string {
        return $c->make(SettingsRepository::class)->get('automation.daily_run_time', '00:05');
    });
}

$results = $scheduler->run($hooks);

$out = defined('STDOUT') ? STDOUT : fopen('php://output', 'w');

/**
 * Log only what happened. The old loop printed one line for every one of the
 * ~30 jobs on every tick — ~50,000 "skipped (not due)" lines a day into
 * storage/cron.log for no information. Ran/failed always log; the skip count
 * is one line when something ran; a fully idle tick logs nothing unless
 * CRON_VERBOSE=1.
 */
$ran = 0;
$failed = 0;
$skipped = 0;

foreach ($results as $job => $result) {
    if ($result['ran']) {
        fwrite($out, sprintf("[%s] %-30s ran\n", date('Y-m-d H:i:s'), $job));
        $ran++;
    } elseif (isset($result['error'])) {
        fwrite($out, sprintf("[%s] %-30s failed: %s\n", date('Y-m-d H:i:s'), $job, $result['error']));
        $failed++;
    } else {
        $skipped++;
    }
}

if ($results === []) {
    fwrite($out, sprintf("[%s] no jobs registered\n", date('Y-m-d H:i:s')));
} elseif ($ran + $failed > 0 && $skipped > 0) {
    fwrite($out, sprintf("[%s] %d job(s) not due\n", date('Y-m-d H:i:s'), $skipped));
} elseif ($ran + $failed === 0) {
    $verbose = $kernel->container->make(Config::class)->env('CRON_VERBOSE', '0') === '1';

    if ($verbose) {
        fwrite($out, sprintf("[%s] idle: %d job(s) not due\n", date('Y-m-d H:i:s'), $skipped));
    }
}

// Opt-in safety net for order acceptance: AcceptOrderJob lives on the
// 'default' queue, which normally needs a dedicated worker process
// (php bin/queue-worker.php). If no worker is running, accepted orders sit
// in Redis and their domains are never registered. Set QUEUE_CRON_DRAIN=1
// in .env ONLY when you rely on the cron instead of the worker — running
// BOTH would let the same job be processed twice. Defaults to off.
if ($kernel->container->make(Config::class)->env('QUEUE_CRON_DRAIN', '0') === '1') {
    /** @var QueueInterface $queue */
    $queue = $kernel->container->make(QueueInterface::class);

    if ($queue instanceof RedisQueue) {
        $drained = (new Worker($queue, 'default'))->run(25);
        fwrite($out, sprintf("[%s] default queue drained %d job(s)\n", date('Y-m-d H:i:s'), $drained));
    }
}
