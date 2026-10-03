<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientImpersonation;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Domains\DomainService;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use DateTimeImmutable;
use Throwable;

/**
 * What a reseller may DO to its own customers: edit their details, send them a
 * password reset, sign in as them, suspend / unsuspend / terminate their services,
 * and look after their domains.
 *
 * Every action re-reads its subject through ResellerClientDirectory, scoped to the
 * store, so a customer, service or domain id from the URL is acted on only once it has
 * been proved to be this store's. Every action is written to the activity log against
 * the customer, with the reseller as the actor, so the platform can see who did what.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 *  - Marking invoices paid, refunding, or adding credit. We collect the customer's
 *    payments (reseller payouts plan §1), so those would let a store hand out what
 *    nobody has paid for.
 *  - Changing the customer's login email or password. A reseller who could do either
 *    could take the account from the customer; it can send a reset link instead.
 *  - Lifting a suspension the store did not make (canLift()).
 *  - Anything at all while the store itself is suspended (storeError()).
 */
final class ResellerClientManager
{
    /** The customer-editable profile fields a reseller may change, with their limits. */
    public const PROFILE_FIELDS = [
        'first_name' => 100,
        'last_name' => 100,
        'company_name' => 191,
        'phone' => 40,
        'address1' => 191,
        'address2' => 191,
        'city' => 100,
        'state' => 100,
        'postcode' => 20,
        'country' => 2,
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ResellerClientDirectory $directory,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly DomainService $domains,
        private readonly ClientImpersonation $impersonation,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly PasswordResetToken $resetToken,
        private readonly EmailDispatcher $mail,
        private readonly Config $config,
        private readonly ActivityLogger $activity
    ) {
    }

    // ------------------------------------------------------------------
    // Decisions — pure, so every branch is testable without a database.
    // ------------------------------------------------------------------

    /** Why the store may not act right now, or null when it may. @param array<string, mixed> $store */
    public static function storeError(array $store): ?string
    {
        return (string) ($store['status'] ?? 'active') === 'active'
            ? null
            : 'Your store is suspended, so changes to customer accounts are paused. Please contact support.';
    }

    /** @param array<string, mixed> $service */
    public static function canSuspend(array $service): bool
    {
        return (string) ($service['status'] ?? '') === 'active';
    }

    /**
     * A store may lift only a suspension IT made, untouched since (migration 0210).
     * Ours — for an unpaid invoice, for abuse, by hand — is ours to lift.
     *
     * @param array<string, mixed> $service
     */
    public static function canLift(array $service, int $storeId): bool
    {
        return (string) ($service['status'] ?? '') === 'suspended'
            && (int) ($service['suspended_by_reseller_id'] ?? 0) === $storeId
            && $storeId > 0;
    }

    /** @param array<string, mixed> $service */
    public static function canTerminate(array $service): bool
    {
        return in_array((string) ($service['status'] ?? ''), ['pending', 'active', 'suspended'], true);
    }

    /**
     * The profile fields to save, or the first problem with them.
     *
     * @param array<string, mixed> $input
     * @return array{fields: array<string, ?string>, error: ?string}
     */
    public static function validateProfile(array $input): array
    {
        $fields = [];

        foreach (self::PROFILE_FIELDS as $name => $max) {
            $value = trim((string) ($input[$name] ?? ''));
            $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

            if (mb_strlen($value) > $max) {
                return ['fields' => [], 'error' => ucfirst(str_replace('_', ' ', $name)) . " can be at most {$max} characters."];
            }

            $fields[$name] = $value === '' ? null : $value;
        }

        if ($fields['first_name'] === null || $fields['last_name'] === null) {
            return ['fields' => [], 'error' => 'First and last name are required.'];
        }

        if ($fields['country'] !== null) {
            $fields['country'] = strtoupper($fields['country']);

            if (preg_match('/^[A-Z]{2}$/', $fields['country']) !== 1) {
                return ['fields' => [], 'error' => 'Country must be a two-letter code, like NG or US.'];
            }
        }

        if ($fields['phone'] !== null && preg_match('/^[0-9+().\-\s]{4,40}$/', $fields['phone']) !== 1) {
            return ['fields' => [], 'error' => 'Phone may contain only digits, spaces and + ( ) - .'];
        }

        return ['fields' => $fields, 'error' => null];
    }

    /**
     * Nameservers to save, or the problem with them: two to six hostnames.
     *
     * @param array<int, mixed> $input
     * @return array{nameservers: array<int, string>, error: ?string}
     */
    public static function validateNameservers(array $input): array
    {
        $out = [];

        foreach ($input as $value) {
            $host = strtolower(rtrim(trim((string) $value), '.'));

            if ($host === '') {
                continue;
            }

            if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $host) !== 1) {
                return ['nameservers' => [], 'error' => "\"{$host}\" is not a valid nameserver hostname."];
            }

            if (!in_array($host, $out, true)) {
                $out[] = $host;
            }
        }

        if (count($out) < 2 || count($out) > 6) {
            return ['nameservers' => [], 'error' => 'Enter between two and six nameservers.'];
        }

        return ['nameservers' => $out, 'error' => null];
    }

    // ------------------------------------------------------------------
    // Actions. Each: $store is the signed-in reseller's own store (never from the
    // request), $actor the reseller's client row.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array{success: bool, message: string}
     */
    public function updateProfile(array $store, array $actor, int $clientId, array $input, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $client = $this->directory->client((int) $store['id'], $clientId);

        if ($client === null) {
            return self::fail('That customer was not found.');
        }

        $valid = self::validateProfile($input);

        if ($valid['error'] !== null) {
            return self::fail($valid['error']);
        }

        $fields = $valid['fields'];
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = ?", array_keys($fields)));

        // Scoped in the UPDATE too, so the write can only ever land on this store's row.
        $this->db->update(
            "UPDATE clients SET {$sets}, updated_at = ? WHERE id = ? AND reseller_id = ?",
            array_merge(array_values($fields), [self::now(), $clientId, (int) $store['id']])
        );

        $changed = array_keys(array_filter($fields, static fn (?string $v, string $k): bool => $v !== ($client[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        $this->log($actor, $store, 'reseller.client_profile_updated', $clientId, 'updated the profile' . ($changed !== [] ? ' (' . implode(', ', $changed) . ')' : ''), $ip);

        return self::ok('Customer details saved.');
    }

    /**
     * Email the customer a password-reset link. The link and the message go out as the
     * store (StoreMailBranding rewrites the platform's address to the store's), and the
     * reseller never sees the link itself.
     *
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function sendPasswordReset(array $store, array $actor, int $clientId, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $client = $this->directory->client((int) $store['id'], $clientId);

        if ($client === null) {
            return self::fail('That customer was not found.');
        }

        if ((string) $client['status'] === 'closed') {
            return self::fail('That account is closed.');
        }

        if ($this->resetTokens->recentlyIssued('client', $clientId, 300)) {
            return self::fail('A reset link was sent to this customer in the last few minutes. Please wait before sending another.');
        }

        $issued = $this->resetToken->generate();
        $this->resetTokens->issue('client', $clientId, $issued['hash']);
        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');

        try {
            $this->mail->sendTemplate('client_password_reset', (string) $client['email'], [
                'first_name' => (string) $client['first_name'],
                'reset_url' => "{$baseUrl}/client/password/reset/{$issued['token']}",
                'company_name' => (string) ($store['brand_name'] ?? '') !== '' ? (string) $store['brand_name'] : brand_name(),
            ], $clientId);
        } catch (Throwable $e) {
            error_log('[CodeVault] reseller password reset email failed: ' . $e->getMessage());

            return self::fail('The reset email could not be sent. Please try again later.');
        }

        $this->log($actor, $store, 'reseller.client_password_reset_sent', $clientId, 'sent a password-reset email', $ip);

        return self::ok('A password-reset link has been emailed to ' . (string) $client['email'] . '.');
    }

    /**
     * The one-time link that signs the reseller in as its customer, on the store's
     * website (ClientImpersonation).
     *
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, url: ?string, error: ?string}
     */
    public function loginLink(array $store, array $actor, int $clientId, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return ['success' => false, 'url' => null, 'error' => $error];
        }

        $client = $this->directory->client((int) $store['id'], $clientId);

        if ($client === null) {
            return ['success' => false, 'url' => null, 'error' => 'That customer was not found.'];
        }

        $label = (string) ($store['brand_name'] ?? '') !== '' ? (string) $store['brand_name'] . ' (reseller)' : 'your reseller account';
        $base = rtrim((string) $this->config->env('APP_URL', ''), '/');

        return $this->impersonation->issue(
            $client,
            'reseller',
            (int) $actor['id'],
            $label,
            "{$base}/client/reseller/clients/{$clientId}",
            $ip
        );
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function suspendService(array $store, array $actor, int $serviceId, string $reason, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $service = $this->directory->service((int) $store['id'], $serviceId);

        if ($service === null) {
            return self::fail('That service was not found.');
        }

        if (!self::canSuspend($service)) {
            return self::fail('Only an active service can be suspended.');
        }

        $reason = mb_substr(trim((string) preg_replace('/\s+/', ' ', $reason)), 0, 200);
        if ($reason === '') {
            $reason = 'Suspended by ' . ((string) ($store['brand_name'] ?? '') !== '' ? (string) $store['brand_name'] : 'your provider') . '.';
        }

        $local = empty($service['server_id']) || empty($service['username']);

        if ($local) {
            // Nothing on a server to suspend — the status IS the suspension (the same
            // rule ServiceRenewalService applies when it unsuspends).
            $this->services->suspend($serviceId, $reason);
        } else {
            $result = $this->provisioning->suspend($serviceId, $reason);

            if (!($result['success'] ?? false)) {
                $this->log($actor, $store, 'reseller.service_suspend_failed', (int) $service['client_id'], "could not suspend service #{$serviceId}: " . (string) ($result['message'] ?? ''), $ip);

                return self::fail('The service could not be suspended: ' . (string) ($result['message'] ?? 'the server did not respond.'));
            }
        }

        $this->services->markSuspendedByReseller($serviceId, (int) $store['id']);
        $this->log($actor, $store, 'reseller.service_suspended', (int) $service['client_id'], "suspended service #{$serviceId} ({$this->serviceLabel($service)}): {$reason}", $ip);

        return self::ok($local
            ? 'Service marked as suspended. It is not set up on a server automatically, so nothing was switched off there — open a ticket if it must be stopped.'
            : 'Service suspended.');
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function unsuspendService(array $store, array $actor, int $serviceId, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $service = $this->directory->service((int) $store['id'], $serviceId);

        if ($service === null) {
            return self::fail('That service was not found.');
        }

        if ((string) $service['status'] !== 'suspended') {
            return self::fail('That service is not suspended.');
        }

        if (!self::canLift($service, (int) $store['id'])) {
            return self::fail('This suspension was made by ' . brand_name() . ' (for example for an unpaid invoice), so only our team can lift it. Please open a support ticket.');
        }

        if (empty($service['server_id']) || empty($service['username'])) {
            $this->services->unsuspend($serviceId);
        } else {
            $result = $this->provisioning->unsuspend($serviceId);

            if (!($result['success'] ?? false)) {
                $this->log($actor, $store, 'reseller.service_unsuspend_failed', (int) $service['client_id'], "could not unsuspend service #{$serviceId}: " . (string) ($result['message'] ?? ''), $ip);

                return self::fail('The service could not be unsuspended: ' . (string) ($result['message'] ?? 'the server did not respond.'));
            }
        }

        $this->log($actor, $store, 'reseller.service_unsuspended', (int) $service['client_id'], "unsuspended service #{$serviceId} ({$this->serviceLabel($service)})", $ip);

        return self::ok('Service unsuspended.');
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function terminateService(array $store, array $actor, int $serviceId, bool $confirmed, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $service = $this->directory->service((int) $store['id'], $serviceId);

        if ($service === null) {
            return self::fail('That service was not found.');
        }

        if (!self::canTerminate($service)) {
            return self::fail('That service is already ' . (string) $service['status'] . '.');
        }

        if (!$confirmed) {
            return self::fail('Tick the box to confirm. Terminating deletes the account and its files on the server, and cannot be undone.');
        }

        if (empty($service['server_id']) || empty($service['username'])) {
            $this->services->terminate($serviceId);
        } else {
            $result = $this->provisioning->terminate($serviceId);

            if (!($result['success'] ?? false)) {
                $this->log($actor, $store, 'reseller.service_terminate_failed', (int) $service['client_id'], "could not terminate service #{$serviceId}: " . (string) ($result['message'] ?? ''), $ip);

                return self::fail('The service could not be terminated: ' . (string) ($result['message'] ?? 'the server did not respond.'));
            }
        }

        $this->log($actor, $store, 'reseller.service_terminated', (int) $service['client_id'], "terminated service #{$serviceId} ({$this->serviceLabel($service)})", $ip);

        return self::ok('Service terminated.');
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function setDomainAutoRenew(array $store, array $actor, int $domainId, bool $on, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $domain = $this->directory->domain((int) $store['id'], $domainId);

        if ($domain === null) {
            return self::fail('That domain was not found.');
        }

        $this->db->update(
            'UPDATE domains d JOIN clients c ON c.id = d.client_id
                SET d.auto_renew = ?, d.updated_at = ?
              WHERE d.id = ? AND c.reseller_id = ?',
            [$on ? 1 : 0, self::now(), $domainId, (int) $store['id']]
        );

        $this->log($actor, $store, 'reseller.domain_auto_renew', (int) $domain['client_id'], 'turned auto-renew ' . ($on ? 'on' : 'off') . ' for ' . (string) $domain['domain_name'], $ip);

        return self::ok('Auto-renew is now ' . ($on ? 'on' : 'off') . ' for ' . (string) $domain['domain_name'] . '.');
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @return array{success: bool, message: string}
     */
    public function toggleDomainLock(array $store, array $actor, int $domainId, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $domain = $this->directory->domain((int) $store['id'], $domainId);

        if ($domain === null) {
            return self::fail('That domain was not found.');
        }

        if ((string) $domain['status'] !== 'active') {
            return self::fail('The transfer lock can only be changed on an active domain.');
        }

        $lock = empty($domain['registrar_lock_enabled']);
        $result = $this->domains->setLock($domainId, $lock);

        if (!($result['success'] ?? false)) {
            return self::fail('The registrar did not accept the change: ' . (string) ($result['message'] ?? 'no response.'));
        }

        $this->log($actor, $store, 'reseller.domain_lock', (int) $domain['client_id'], ($lock ? 'locked ' : 'unlocked ') . (string) $domain['domain_name'], $ip);

        return self::ok((string) $domain['domain_name'] . ' is now ' . ($lock ? 'locked' : 'unlocked') . '.');
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $actor
     * @param array<int, mixed> $nameservers
     * @return array{success: bool, message: string}
     */
    public function saveDomainNameservers(array $store, array $actor, int $domainId, array $nameservers, ?string $ip = null): array
    {
        if (($error = self::storeError($store)) !== null) {
            return self::fail($error);
        }

        $domain = $this->directory->domain((int) $store['id'], $domainId);

        if ($domain === null) {
            return self::fail('That domain was not found.');
        }

        if ((string) $domain['status'] !== 'active') {
            return self::fail('Nameservers can only be changed on an active domain.');
        }

        $valid = self::validateNameservers($nameservers);

        if ($valid['error'] !== null) {
            return self::fail($valid['error']);
        }

        $result = $this->domains->saveNameservers($domainId, $valid['nameservers']);

        if (!($result['success'] ?? false)) {
            return self::fail('The registrar did not accept the nameservers: ' . (string) ($result['message'] ?? 'no response.'));
        }

        $this->log($actor, $store, 'reseller.domain_nameservers', (int) $domain['client_id'], 'set nameservers for ' . (string) $domain['domain_name'] . ' to ' . implode(', ', $valid['nameservers']), $ip);

        return self::ok('Nameservers updated for ' . (string) $domain['domain_name'] . '.');
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $service */
    private function serviceLabel(array $service): string
    {
        $label = (string) ($service['product_name'] ?? 'service');
        $where = (string) ($service['domain'] ?? '') !== '' ? (string) $service['domain'] : (string) ($service['hostname'] ?? '');

        return $where !== '' ? "{$label} — {$where}" : $label;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $store
     */
    private function log(array $actor, array $store, string $action, int $clientId, string $what, ?string $ip): void
    {
        $this->activity->log(
            'client',
            (int) $actor['id'],
            $action,
            'client',
            $clientId,
            mb_substr('Reseller ' . (string) ($store['brand_name'] ?? '') . ' (store #' . (int) $store['id'] . ') ' . $what, 0, 500),
            $ip
        );
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @return array{success: bool, message: string} */
    private static function ok(string $message): array
    {
        return ['success' => true, 'message' => $message];
    }

    /** @return array{success: bool, message: string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
