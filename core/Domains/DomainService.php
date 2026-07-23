<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Clients\ClientRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\RegistrarModule;

/**
 * The domain engine orchestration (blueprint §4.4): turns a domain action
 * into a call against whichever RegistrarModule the domain's assigned
 * registrar uses. Mirrors ProvisioningService's shape from R6 — module
 * failures are recorded on the domain (`provisioning_error`) rather than
 * thrown, so a failed action is visible and retryable.
 */
final class DomainService
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly RegistrarRepository $registrars,
        private readonly ModuleManager $modules,
        private readonly HookDispatcher $hooks,
        private readonly ClientRepository $clients
    ) {
    }

    /**
     * Live availability check against the registrar assigned to this TLD
     * in domain_pricing — used by the domain-registration search page
     * before a client commits to buying. No domain record exists yet at
     * this point (that only happens at checkout), so resolution is by
     * registrar slug directly rather than through an owned domain row.
     *
     * @return array{success: bool, available: bool, message: string, expiryDate: ?string}
     */
    public function checkAvailability(string $domainName, string $registrarSlug): array
    {
        /** @var RegistrarModule|null $module */
        $module = $this->modules->get(RegistrarModule::class, $registrarSlug);

        if ($module === null) {
            return ['success' => false, 'available' => false, 'message' => "Unknown registrar module \"{$registrarSlug}\".", 'expiryDate' => null];
        }

        $config = $this->registrars->configFor($registrarSlug);

        try {
            $result = $module->checkAvailability(['domain' => $domainName, 'registrar' => $config]);
        } catch (\Throwable $e) {
            return ['success' => false, 'available' => false, 'message' => 'Availability check failed: ' . $e->getMessage(), 'expiryDate' => null];
        }

        return [
            'success' => (bool) ($result['success'] ?? false),
            'available' => (bool) ($result['available'] ?? false),
            'message' => (string) ($result['status'] ?? ($result['success'] ?? false ? 'Checked.' : 'Availability check failed.')),
            'expiryDate' => $result['expiryDate'] ?? null,
        ];
    }

    /** @return array{success: bool, message: string} */
    public function register(int $domainId, int $years = 1): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $client = $this->clients->find((int) $domain['client_id']);

        $result = $module->register([
            'domain' => $domain['domain_name'],
            'years' => $years,
            'id_protection' => (bool) $domain['id_protection_enabled'],
            'registrar' => $config,
            'client' => $client ?? [],
            'registrarClientId' => $client['registrar_client_id'] ?? null,
        ]);

        if (!$result['success']) {
            $this->domains->recordProvisioningError($domainId, $result['message']);

            return $result;
        }

        // Some registrars (ConnectReseller) have to create a customer
        // record on their own side the first time a client registers a
        // domain — persist that ID so every later action for this client
        // reuses it instead of creating a duplicate customer.
        if (isset($result['registrarClientId']) && $client !== null) {
            $this->clients->updateRegistrarClientId((int) $client['id'], (string) $result['registrarClientId']);
        }

        $registrationDate = $result['registrationDate'] ?? date('Y-m-d');
        $expiryDate = $result['expiryDate'] ?? date('Y-m-d', strtotime("+{$years} years"));

        $this->domains->activate($domainId, $result['registrarDomainId'] ?? '', $registrationDate, $expiryDate);
        // next_due_date == expiry_date — the renewal billing engine
        // generates invoices N days *ahead* of this, not on it.
        $this->domains->advanceRenewal($domainId, $expiryDate, $expiryDate);
        $this->domains->recordProvisioningError($domainId, null);

        // Populate the nameservers cache immediately so a freshly-registered
        // domain doesn't show blank/stale NS records until the first manual
        // refresh or the nightly sync job — best-effort, a failure here
        // doesn't undo the registration that just succeeded.
        $this->getNameservers($domainId);

        $this->hooks->fire(HookPoints::DOMAIN_REGISTERED, ['domainId' => $domainId]);

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function renew(int $domainId, int $years = 1): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        // Validate domain grace and redemption limits
        $tld = strtolower(trim((string) ($domain['tld'] ?? '')));
        if ($tld === '') {
            $parts = explode('.', strtolower(trim((string) $domain['domain_name'])));
            if (count($parts) > 1) {
                array_shift($parts);
                $tld = '.' . implode('.', $parts);
            }
        }

        if ($tld !== '' && !empty($domain['expiry_date'])) {
            $tldPricing = $this->db->selectOne('SELECT * FROM domain_pricing WHERE tld = ?', [$tld]);
            if ($tldPricing !== null) {
                $now = new DateTimeImmutable();
                $expiryDate = new DateTimeImmutable((string) $domain['expiry_date']);
                $daysExpired = (int) $now->diff($expiryDate)->format('%r%a');
                if ($daysExpired < 0) {
                    $daysPastExpiry = abs($daysExpired);
                    $graceDays = (int) ($tldPricing['grace_period_days'] ?? 30);
                    $redemptionDays = (int) ($tldPricing['redemption_period_days'] ?? 30);
                    $maxDays = $graceDays + $redemptionDays;

                    if ($daysPastExpiry > $maxDays) {
                        return [
                            'success' => false,
                            'message' => "Domain renewal is no longer possible because it has exceeded the combined Grace Period ({$graceDays} days) and Redemption Period ({$redemptionDays} days).",
                        ];
                    }
                }
            }
        }

        $client = $this->clients->find((int) $domain['client_id']);

        $result = $module->renew([
            'domain' => $domain['domain_name'],
            'years' => $years,
            'registrar' => $config,
            'registrarClientId' => $client['registrar_client_id'] ?? null,
        ]);

        if (!$result['success']) {
            $this->domains->recordProvisioningError($domainId, $result['message']);

            return $result;
        }

        $expiryDate = $result['expiryDate'] ?? date('Y-m-d', strtotime("{$domain['expiry_date']} +{$years} years"));
        $this->domains->advanceRenewal($domainId, $expiryDate, $expiryDate);
        $this->domains->recordProvisioningError($domainId, null);

        $this->hooks->fire(HookPoints::DOMAIN_RENEWED, ['domainId' => $domainId]);

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function setLock(int $domainId, bool $lock): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $result = $module->setRegistrarLock([
            'domain' => $domain['domain_name'],
            'lock' => $lock,
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ]);

        if ($result['success']) {
            $this->domains->setLock($domainId, $lock);
            if (!empty($result['registrarDomainId'])) {
                $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
            }
        }

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function setIdProtection(int $domainId, bool $enabled): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $params = [
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ];
        $result = $enabled ? $module->enableIdProtection($params) : $module->disableIdProtection($params);

        if ($result['success']) {
            $this->domains->setIdProtection($domainId, $enabled);
            if (!empty($result['registrarDomainId'])) {
                $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
            }
        }

        return $result;
    }

    /** @param array<int, string> $nameservers */
    public function saveNameservers(int $domainId, array $nameservers): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $cleanNs = array_values(array_filter(array_map('trim', $nameservers)));

        $result = $module->saveNameservers([
            'domain' => $domain['domain_name'],
            'nameservers' => $cleanNs,
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
            'ns1' => $cleanNs[0] ?? null,
            'ns2' => $cleanNs[1] ?? null,
            'ns3' => $cleanNs[2] ?? null,
            'ns4' => $cleanNs[3] ?? null,
            'ns5' => $cleanNs[4] ?? null,
            'ns6' => $cleanNs[5] ?? null,
        ]);

        if ($result['success']) {
            $savedNs = !empty($result['nameservers']) ? $result['nameservers'] : $cleanNs;
            $this->domains->updateNameservers($domainId, $savedNs);
            if (!empty($result['registrarDomainId'])) {
                $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
            }
        }

        return $result;
    }

    /** @return array{success: bool, nameservers: array<int, string>} */
    public function getNameservers(int $domainId): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'nameservers' => []];
        }

        $result = $module->getNameservers([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ]);

        if ($result['success'] && !empty($result['nameservers'])) {
            $this->domains->updateNameservers($domainId, $result['nameservers']);
            if (!empty($result['registrarDomainId'])) {
                $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
            }
        }

        return $result;
    }

    /** @return array{success: bool, eppCode?: string, message: string} */
    public function getEppCode(int $domainId): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $result = $module->getEppCode([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ]);

        if (!empty($result['registrarDomainId'])) {
            $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
        }

        return $result;
    }

    /** @return array{success: bool, contacts: array<string, mixed>, message?: string} */
    public function getContactInfo(int $domainId): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'contacts' => [], 'message' => $error];
        }

        return $module->getContactInfo([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarContactId' => $domain['registrar_contact_id'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $contact */
    public function saveContactInfo(int $domainId, array $contact): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $client = $this->clients->find((int) $domain['client_id']);

        $result = $module->saveContactInfo([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarContactId' => $domain['registrar_contact_id'] ?? null,
            'registrarClientId' => $client['registrar_client_id'] ?? null,
            'client' => $client ?? [],
            // 'contacts' (plural) is the existing convention Local/
            // Upperlink's saveContactInfo() already read — matching it here
            // so this call actually reaches their stored data instead of
            // silently no-op'ing against a key those modules never read.
            'contacts' => $contact,
        ]);

        if ($result['success'] && $client !== null && isset($result['registrarClientId'])) {
            $this->clients->updateRegistrarClientId((int) $client['id'], (string) $result['registrarClientId']);
        }

        if ($result['success'] && isset($result['registrarContactId'])) {
            $this->domains->updateContactId($domainId, (string) $result['registrarContactId']);
        }

        return $result;
    }

    /** Reconciles local status/expiry with the registrar's record of truth. */
    public function sync(int $domainId): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $result = $module->sync(['domain' => $domain['domain_name'], 'registrar' => $config]);

        if (!$result['success']) {
            return $result;
        }

        if (isset($result['expiryDate'])) {
            $this->domains->advanceRenewal($domainId, $result['expiryDate'], $result['expiryDate']);
        }

        if (!empty($result['registrarDomainId'])) {
            $this->domains->updateRegistrarDomainId($domainId, (string) $result['registrarDomainId']);
        }

        if (!empty($result['nameservers']) && is_array($result['nameservers'])) {
            $this->domains->updateNameservers($domainId, $result['nameservers']);
        }

        if (isset($result['status'])) {
            $newStatus = $result['status'];
            if (is_array($newStatus)) {
                $newStatus = reset($newStatus) ?: '';
                if (is_array($newStatus)) {
                    $newStatus = (string) (current($newStatus) ?: '');
                }
            }
            $newStatusStr = trim((string) $newStatus);

            if ($newStatusStr !== '' && $newStatusStr !== (string) $domain['status']) {
                $this->domains->setStatus($domainId, $newStatusStr);

                if ($newStatusStr === 'expired') {
                    $this->hooks->fire(HookPoints::DOMAIN_EXPIRED, ['domainId' => $domainId]);
                }
            }
        }

        return $result;
    }

    /** @return array{total: int, success: int, failed: int} */
    public function bulkSync(?string $registrarSlug = null): array
    {
        $domains = !empty($registrarSlug) ? $this->domains->allForRegistrar($registrarSlug) : $this->domains->all();
        $success = 0;
        $failed = 0;

        foreach ($domains as $d) {
            $res = $this->sync((int) $d['id']);
            if ($res['success']) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['total' => count($domains), 'success' => $success, 'failed' => $failed];
    }

    /** @return array{total: int, success: int, failed: int} */
    public function bulkRefreshNameservers(?string $registrarSlug = null): array
    {
        $domains = !empty($registrarSlug) ? $this->domains->allForRegistrar($registrarSlug) : $this->domains->all();
        $success = 0;
        $failed = 0;

        foreach ($domains as $d) {
            $res = $this->getNameservers((int) $d['id']);
            if ($res['success'] && !empty($res['nameservers'])) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['total' => count($domains), 'success' => $success, 'failed' => $failed];
    }

    /** @return array<int, array<string, mixed>> */
    public function getChildNameservers(int $domainId): array
    {
        return $this->domains->getChildNameservers($domainId);
    }

    public function addChildNameserver(int $domainId, string $hostname, string $ip): array
    {
        $hostname = trim($hostname);
        $ip = trim($ip);

        if ($hostname === '' || $ip === '') {
            return ['success' => false, 'message' => 'Hostname and IP address are required.'];
        }

        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error === null && $module !== null && method_exists($module, 'registerChildNs')) {
            $module->registerChildNs([
                'domain' => $domain['domain_name'],
                'hostname' => $hostname,
                'ip' => $ip,
                'registrar' => $config,
                'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
            ]);
        }

        $id = $this->domains->addChildNameserver($domainId, $hostname, $ip);
        return ['success' => true, 'id' => $id, 'message' => 'Private nameserver added successfully.'];
    }

    public function deleteChildNameserver(int $domainId, int $childNsId): array
    {
        $cnsList = $this->domains->getChildNameservers($domainId);
        $target = null;
        foreach ($cnsList as $cns) {
            if ((int) $cns['id'] === $childNsId) {
                $target = $cns;
                break;
            }
        }

        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($target !== null && $error === null && $module !== null && method_exists($module, 'deleteChildNs')) {
            $module->deleteChildNs([
                'domain' => $domain['domain_name'],
                'hostname' => $target['hostname'],
                'ip' => $target['ip_address'],
                'registrar' => $config,
                'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
            ]);
        }

        $this->domains->deleteChildNameserver($domainId, $childNsId);
        return ['success' => true, 'message' => 'Private nameserver deleted successfully.'];
    }

    /** @return array<int, array<string, mixed>> */
    public function getDnsRecords(int $domainId): array
    {
        return $this->domains->getDnsRecords($domainId);
    }

    public function addDnsRecord(int $domainId, string $type, string $name, string $content, int $priority = 10, int $ttl = 3600): array
    {
        $type = strtoupper(trim($type));
        $name = trim($name);
        $content = trim($content);

        if ($name === '' || $content === '') {
            return ['success' => false, 'message' => 'Record name and target content are required.'];
        }

        $id = $this->domains->addDnsRecord($domainId, $type, $name, $content, $priority, $ttl);
        return ['success' => true, 'id' => $id, 'message' => 'DNS record added successfully.'];
    }

    public function deleteDnsRecord(int $domainId, int $recordId): array
    {
        $this->domains->deleteDnsRecord($domainId, $recordId);
        return ['success' => true, 'message' => 'DNS record deleted.'];
    }

    /** @param array<int, int> $ids */
    public function bulkDelete(array $ids): int
    {
        return $this->domains->bulkDelete($ids);
    }

    /** @param array<int, int> $ids */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        $validStatuses = ['active', 'pending', 'expired', 'cancelled', 'transferred', 'fraud'];
        $status = strtolower(trim($status));
        if (!in_array($status, $validStatuses, true)) {
            return 0;
        }
        return $this->domains->bulkUpdateStatus($ids, $status);
    }

    private function resolveModuleSlug(string $slug): string
    {
        $lower = strtolower($slug);
        if (str_contains($lower, 'upperlink')) {
            return 'upperlink';
        }
        if (str_contains($lower, 'connectreseller')) {
            return 'connectreseller';
        }
        if (str_contains($lower, 'resellerclub')) {
            return 'resellerclub';
        }
        if (str_contains($lower, 'namecheap')) {
            return 'namecheap';
        }
        return $slug;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?RegistrarModule, 2: array<string, mixed>, 3: ?string}
     */
    private function context(int $domainId): array
    {
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            return [null, null, [], 'Domain not found.'];
        }

        $rawSlug = (string) ($domain['registrar_slug'] ?? '');
        $targetSlug = $this->resolveModuleSlug($rawSlug);

        /** @var RegistrarModule|null $module */
        $module = $this->modules->get(RegistrarModule::class, $targetSlug);

        if ($module === null) {
            $module = $this->modules->get(RegistrarModule::class, strtolower($rawSlug));
        }

        if ($module === null) {
            return [$domain, null, [], "Unknown registrar module \"{$rawSlug}\"."];
        }

        $config = $this->registrars->configFor($rawSlug);
        if (empty($config)) {
            $config = $this->registrars->configFor($targetSlug);
        }

        return [$domain, $module, $config, null];
    }
}
