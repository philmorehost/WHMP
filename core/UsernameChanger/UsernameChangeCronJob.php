<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Cron\CronJob;
use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use Throwable;

/**
 * Every 5 minutes, while the add-on is active (plan §10):
 *  - run due queued requests (incl. retries), at most one per server per run;
 *  - expire unconfirmed requests;
 *  - release stale `processing` claims (re-verified by the executor on retry);
 *  - advance requests whose fee invoice was paid by a route that fired no hook,
 *    and cancel those whose invoice was cancelled;
 *  - refresh the local copy of each server's accounts (keeps the precheck instant);
 *  - prune old history and re-create missing email templates (hourly-ish).
 */
final class UsernameChangeCronJob implements CronJob
{
    public const SLUG = 'cpanel-username-changer';
    private const BATCH = 10;
    private const STALE_LOCK_MINUTES = 30;

    public function __construct(
        private readonly AddonModuleRepository $addons,
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameChangeService $service,
        private readonly UsernameChangeExecutor $executor,
        private readonly UsernameAvailability $availability,
        private readonly UsernameChangerSettings $settings,
        private readonly Database $db
    ) {
    }

    public function name(): string
    {
        return 'username-changer';
    }

    public function frequencyMinutes(): int
    {
        return 5;
    }

    public function handle(): void
    {
        if (!$this->addons->isActive(self::SLUG)) {
            return;
        }

        $steps = [
            fn () => $this->releaseStaleLocks(),
            fn () => array_map(fn ($r) => $this->service->expire($r), $this->requests->expiredConfirmations()),
            fn () => $this->service->reconcilePayments(),
            fn () => $this->runDue(),
            fn () => $this->refreshServers(),
            fn () => $this->housekeeping(),
        ];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable) {
                // One failing step never stops the others.
            }
        }
    }

    private function runDue(): void
    {
        $busy = [];

        foreach ($this->requests->due(self::BATCH * 3) as $r) {
            $server = (int) ($r['server_id'] ?? 0);

            if (isset($busy[$server]) || count($busy) >= self::BATCH) {
                continue;
            }

            $busy[$server] = true;
            $this->executor->run((int) $r['id']);
        }
    }

    private function releaseStaleLocks(): void
    {
        foreach ($this->requests->staleLocks(self::STALE_LOCK_MINUTES) as $r) {
            // Back to queued; the executor re-checks the server before renaming,
            // so a rename that actually finished is detected, not repeated.
            if ($this->requests->transition((int) $r['id'], 'processing', 'queued', ['lock_token' => null, 'next_attempt_at' => null])) {
                $this->requests->event((int) $r['id'], 'lock_released', 'system', null, null, 'Stale processing claim released.');
            }
        }
    }

    private function refreshServers(): void
    {
        foreach (array_slice($this->availability->staleServers(), 0, 5) as $serverId) {
            $this->availability->refreshServer($serverId);
        }
    }

    private function housekeeping(): void
    {
        // Roughly hourly: 1 in 12 five-minute runs.
        if ((int) date('i') >= 5) {
            return;
        }

        $days = $this->settings->retentionDays();
        $this->requests->pruneEvents($days);
        $this->requests->prune($days);
        $this->requests->pruneThrottle();
        UsernameChangeTemplates::ensure($this->db);
    }
}
