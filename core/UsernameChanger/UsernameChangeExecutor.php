<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Database;
use CodeVault\Provisioning\ProvisioningService;
use DateTimeImmutable;
use Throwable;

/**
 * Runs ONE queued request (plan §8): claim → preflight → rename → verify → sync.
 *
 * The claim is a conditional UPDATE, so a request picked up by both the queue
 * worker and cron runs once. Nothing here trusts the HTTP outcome of the rename:
 * ProvisioningService asks the server whether the new account exists.
 */
final class UsernameChangeExecutor
{
    /** Backoff for transient failures (unreachable server, timeout): 5 min, 30 min, 2 h. */
    public const BACKOFF_MINUTES = [5, 30, 120];

    public function __construct(
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameAvailability $availability,
        private readonly ProvisioningService $provisioning,
        private readonly UsernameChangerSettings $settings,
        private readonly UsernameChangeNotifier $notifier,
        private readonly Database $db,
        private readonly ?ActivityLogger $activity = null
    ) {
    }

    /** @return string the request's status afterwards */
    public function run(int $requestId): string
    {
        $lock = bin2hex(random_bytes(16));

        if (!$this->requests->claim($requestId, $lock)) {
            return (string) ($this->requests->find($requestId)['status'] ?? 'missing');
        }

        $r = $this->requests->find($requestId);
        $this->requests->event($requestId, 'claimed', 'system');

        try {
            return $this->execute($r);
        } catch (Throwable $e) {
            return $this->fail($r, 'Unexpected error: ' . $e->getMessage(), '', true);
        }
    }

    /** @param array<string, mixed> $r */
    private function execute(array $r): string
    {
        $id = (int) $r['id'];
        $service = $this->db->selectOne('SELECT id, server_id, username, domain, status FROM services WHERE id = ?', [(int) $r['service_id']]);

        if ($service === null) {
            return $this->fail($r, 'The service no longer exists.', '', false);
        }

        $current = strtolower((string) $service['username']);
        $new = (string) $r['new_username'];

        // Already done (a previous attempt timed out after WHM finished)?
        if ($current === $new) {
            return $this->complete($r, true, 'Service already uses the new name.');
        }

        // The account must still exist under the name we hold.
        $exists = $this->provisioning->accountExists((int) $service['id'], $current);

        if (!$exists['known']) {
            return $this->fail($r, 'Could not reach the hosting server: ' . $exists['message'], '', true);
        }

        if (!$exists['exists']) {
            // Renamed outside WHMP? See whether the server already has the new name,
            // or another name for this domain.
            $newExists = $this->provisioning->accountExists((int) $service['id'], $new);

            if ($newExists['known'] && $newExists['exists']) {
                $this->sync((int) $service['id'], $new);
                $this->requests->event($id, 'detected_external', 'system', null, null, 'The server already uses the new name.');

                return $this->complete($r, true, 'Server already renamed.');
            }

            $serverId = (int) ($service['server_id'] ?? 0);

            if ($serverId > 0) {
                $this->availability->refreshServer($serverId);
                $real = $this->availability->accountForDomain($serverId, (string) $service['domain']);

                if ($real !== null && $real !== $current) {
                    $this->sync((int) $service['id'], $real);
                    $this->requests->patch($id, ['old_username' => substr($real, 0, 16)]);
                    $this->requests->event($id, 'detected_external', 'system', null, null, "Account was renamed outside WHMP: {$current} → {$real}. Billing record updated.");

                    if ($real === $new) {
                        return $this->complete($r, true, 'Server already renamed.');
                    }

                    $current = $real;
                }
            }

            if ($current === strtolower((string) $service['username'])) {
                return $this->fail($r, "The account “{$current}” was not found on the server.", '', false);
            }
        }

        // Preflight: every rule again, against the CURRENT state (our own
        // reservation is excluded because it belongs to this service).
        $check = $this->availability->deep($new, ['id' => (int) $service['id'], 'server_id' => $service['server_id'], 'username' => $current]);

        if (!$check['ok']) {
            $this->requests->event($id, 'preflight_failed', 'system', null, null, $check['message']);

            return $this->fail($r, 'Preflight: ' . $check['message'], '', $check['code'] === 'unverified');
        }

        $result = $this->provisioning->changeUsername((int) $service['id'], $new, (int) $r['rename_db_objects'] === 1, $id);
        $this->requests->patch($id, ['server_response' => mb_substr((string) $result['raw'], 0, 2000) ?: null]);

        if (!$result['renamed']) {
            return $this->fail($r, (string) $result['message'], (string) $result['raw'], (bool) $result['transient']);
        }

        if ((int) ($service['server_id'] ?? 0) > 0) {
            $this->availability->recordRename((int) $service['server_id'], $current, $new);
        }

        $this->requests->event($id, 'renamed', 'system', null, null, "{$current} → {$new}");

        if (!$result['synced'] && !$this->sync((int) $service['id'], $new)) {
            return $this->complete($r, false, 'Renamed, but the billing record could not be updated.');
        }

        return $this->complete($r, true, 'Renamed.');
    }

    /** Direct fallback write of services.username. */
    private function sync(int $serviceId, string $username): bool
    {
        try {
            $this->db->update('UPDATE services SET username = ?, updated_at = ? WHERE id = ?', [$username, UsernameChangeRepository::now(), $serviceId]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $r */
    private function complete(array $r, bool $synced, string $detail): string
    {
        $id = (int) $r['id'];
        $this->requests->transition($id, 'processing', 'completed', [
            'completed_at' => UsernameChangeRepository::now(),
            'sync_state' => $synced ? 'ok' : 'mismatch',
            'lock_token' => null,
            'last_error' => null,
        ]);
        $this->requests->event($id, $synced ? 'synced' : 'mismatch', 'system', null, null, $detail);

        try {
            $this->activity?->log('system', null, 'service.username_changed', 'service', (int) $r['service_id'], "cPanel username changed {$r['old_username']} → {$r['new_username']} (request #{$id})");
        } catch (Throwable) {
        }

        $detailRow = $this->requests->findDetailed($id);

        if ($detailRow !== null) {
            $this->notifier->completed($detailRow);

            if (!$synced) {
                $this->notifier->staffMismatch($detailRow);
            }
        }

        return 'completed';
    }

    /** @param array<string, mixed> $r */
    private function fail(array $r, string $error, string $raw, bool $transient): string
    {
        $id = (int) $r['id'];
        $fresh = $this->requests->find($id) ?? $r;
        $attempts = (int) ($fresh['attempts'] ?? 1);
        $retry = $transient && $attempts < $this->settings->maxAttempts();
        $set = ['last_error' => mb_substr($error, 0, 2000), 'lock_token' => null];

        if ($raw !== '') {
            $set['server_response'] = mb_substr($raw, 0, 2000);
        }

        if ($retry) {
            $minutes = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
            $set['next_attempt_at'] = (new DateTimeImmutable("+{$minutes} minutes"))->format('Y-m-d H:i:s');
            $this->requests->transition($id, 'processing', 'queued', $set);
            $this->requests->event($id, 'retry_scheduled', 'system', null, null, $error . " (retry in {$minutes} min)");

            return 'queued';
        }

        $this->requests->transition($id, 'processing', 'failed', $set);
        $this->requests->event($id, 'failed', 'system', null, null, $error);
        $detail = $this->requests->findDetailed($id);

        if ($detail !== null) {
            // Staff-initiated changes report on screen; the client is only told
            // about their own request's final failure.
            if ((string) $detail['requested_by_type'] !== 'admin') {
                $this->notifier->failed($detail);
            }

            $this->notifier->staffFailed($detail);
        }

        return 'failed';
    }
}
