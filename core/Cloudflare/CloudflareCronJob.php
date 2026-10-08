<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Cron\CronJob;
use CodeVault\Modules\AddonModuleRepository;
use Throwable;

/**
 * Cloudflare add-on housekeeping (every 15 minutes, a no-op while the add-on is
 * inactive or not connected):
 *   1. completes DNSSEC DS-cache waits before disabling signing or restoring
 *      nameservers, then deletes zones whose grace period has run out;
 *   2. checks pending zones for activation — each at most once an hour — and
 *      sends the nameserver reminders on day 3 and day 7;
 *   3. reconciles every live zone with Cloudflare and with its service once a
 *      day, catching status changes made without the hook.
 */
final class CloudflareCronJob implements CronJob
{
    public const SLUG = 'cloudflare';

    private const DELETE_BATCH = 20;
    private const PENDING_BATCH = 40;
    private const RECONCILE_BATCH = 40;

    /** Days after creation when a pending zone's reminders go out. */
    private const REMINDER_DAYS = [3, 7];

    public function __construct(
        private readonly AddonModuleRepository $addons,
        private readonly CloudflareSettings $settings,
        private readonly CloudflareZoneRepository $zones,
        private readonly CloudflareService $service,
        private readonly ServiceRepository $services,
        private readonly ?CloudflareNotifier $notifier = null,
        private readonly ?CloudflareFeatures $features = null
    ) {
    }

    public function name(): string
    {
        return 'cloudflare';
    }

    public function frequencyMinutes(): int
    {
        return 15;
    }

    public function handle(): void
    {
        try {
            if (!$this->addons->isActive(self::SLUG) || !$this->settings->connected()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $this->run(time());
    }

    /** @return array{deleted:int,checked:int,reminded:int,reconciled:int,restored:int,dnssec_off:int} */
    public function run(int $now): array
    {
        $stats = ['deleted' => 0, 'checked' => 0, 'reminded' => 0, 'reconciled' => 0, 'restored' => 0, 'dnssec_off' => 0];
        $system = ['type' => 'system', 'id' => null];
        $stamp = date('Y-m-d H:i:s', $now);

        foreach ($this->zones->dueFor('ns_restore_after', $stamp, self::DELETE_BATCH) as $zone) {
            if ($this->service->restoreDeferredNameservers((int) $zone['id'], $now)['ok']) { $stats['restored']++; }
        }
        if ($this->features !== null) {
            foreach ($this->zones->dueFor('dnssec_disable_after', $stamp, self::DELETE_BATCH) as $zone) {
                if ($this->features->finishDnssecDisable((int) $zone['id'], $now)['ok']) { $stats['dnssec_off']++; }
            }
        }

        foreach ($this->zones->dueForDeletion(date('Y-m-d H:i:s', $now), self::DELETE_BATCH) as $zone) {
            if ($this->service->deleteNow((int) $zone['id'], $system)['ok']) {
                $stats['deleted']++;
            }
        }

        $hourAgo = $now - 3600;
        $checked = 0;

        foreach ($this->zones->pending(200) as $zone) {
            if ($checked >= self::PENDING_BATCH) {
                break;
            }

            $last = $zone['last_checked_at'] !== null ? strtotime((string) $zone['last_checked_at']) : false;

            if ($last !== false && $last > $hourAgo) {
                continue;
            }

            $checked++;
            // Cloudflare's own re-check only for zones whose nameservers we switched;
            // for the rest a status read is enough (Cloudflare checks on its own).
            $this->service->checkActivation((int) $zone['id'], $system, (int) $zone['ns_switched_by_us'] === 1);
            $stats['checked']++;

            $fresh = $this->zones->find((int) $zone['id']);

            if ($fresh !== null && $fresh['status'] !== 'active' && $fresh['status'] !== 'deleted' && $this->remind($fresh, $now)) {
                $stats['reminded']++;
            }
        }

        foreach ($this->zones->staleLive(date('Y-m-d H:i:s', $now - 86400), self::RECONCILE_BATCH) as $zone) {
            if ($this->service->reconcile((int) $zone['id'])['ok']) {
                $stats['reconciled']++;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $zone */
    private function remind(array $zone, int $now): bool
    {
        $sent = (int) $zone['reminders_sent'];

        if (!isset(self::REMINDER_DAYS[$sent]) || $zone['service_id'] === null) {
            return false;
        }

        $created = strtotime((string) $zone['created_at']);

        if ($created === false || $now - $created < self::REMINDER_DAYS[$sent] * 86400) {
            return false;
        }

        $this->zones->update((int) $zone['id'], ['reminders_sent' => $sent + 1]);
        $service = $this->services->find((int) $zone['service_id']);

        if ($service !== null && (string) $service['status'] === 'active') {
            $registered = $this->service->registeredDomain((string) $zone['name'], (int) $zone['client_id']) !== null;
            $this->notifier?->reminder($zone, $service, $registered);
            $this->zones->log((int) $zone['id'], 'system', null, 'reminder', 'Nameserver reminder ' . ($sent + 1) . ' sent.');
        }

        return true;
    }
}
