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

        $result = $module->saveNameservers(array_combine(
            ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'],
            array_pad(array_slice($nameservers, 0, 5), 5, null)
        ) + [
            'domain' => $domain['domain_name'],
            'nameservers' => $nameservers,
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ]);

        if ($result['success']) {
            $this->domains->updateNameservers($domainId, $nameservers);
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

        $result = $module->getNameservers(['domain' => $domain['domain_name'], 'registrar' => $config]);

        if ($result['success']) {
            $this->domains->updateNameservers($domainId, $result['nameservers']);
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

        return $module->getEppCode([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarDomainId' => $domain['registrar_domain_id'] ?? null,
        ]);
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

        if (isset($result['status']) && $result['status'] !== $domain['status']) {
            $this->domains->setStatus($domainId, $result['status']);

            if ($result['status'] === 'expired') {
                $this->hooks->fire(HookPoints::DOMAIN_EXPIRED, ['domainId' => $domainId]);
            }
        }

        return $result;
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

        /** @var RegistrarModule|null $module */
        $module = $this->modules->get(RegistrarModule::class, $domain['registrar_slug']);

        if ($module === null) {
            return [$domain, null, [], "Unknown registrar module \"{$domain['registrar_slug']}\"."];
        }

        $config = $this->registrars->configFor($domain['registrar_slug']);

        return [$domain, $module, $config, null];
    }
}
