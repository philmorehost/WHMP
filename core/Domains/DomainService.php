<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\RegistrarModule;
use DateTimeImmutable;

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
        private readonly ClientRepository $clients,
        // renew() reads grace/redemption windows straight from domain_pricing;
        // there is no repository for that lookup yet.
        private readonly Database $db,
        private readonly ActivityLogger $activity,
        private readonly \CodeVault\Clients\ClientContactRepository $contacts
    ) {
    }

    /**
     * The hidden $0 "Domain Registration" carrier product a standalone
     * domain (register/transfer, not attached to a require_domain product)
     * rides on — the cart has no concept of a domain-only line (see
     * migration 0103's docblock), so every such domain needs a real
     * product_id to attach its domain_options to. Shared by every path that
     * adds a standalone domain to an order (the public search page,
     * AdminOrderController) so there is exactly one carrier product, not
     * one per caller that happened to reimplement this lookup.
     */
    public function carrierProductId(): int
    {
        $carrier = $this->db->selectOne("SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1");

        if ($carrier !== null) {
            return (int) $carrier['id'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $groupId = $this->db->selectOne("SELECT id FROM product_groups WHERE name = 'System' LIMIT 1")['id'] ?? null;

        if ($groupId === null) {
            $groupId = $this->db->insert(
                'INSERT INTO product_groups (name, description, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                ['System', 'Internal carrier products — not shown in the store.', 9999, $now, $now]
            );
        }

        $productId = $this->db->insert(
            'INSERT INTO products (product_group_id, name, description, status, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $groupId, 'Domain Registration', 'Internal carrier for standalone domain registrations — not a purchasable product on its own.', 'hidden', 'other', 0, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price) VALUES (?, ?, ?, ?)',
            [(int) $productId, 'annually', 0.00, 0.00]
        );

        // Best-effort: a logging failure must never block a client's
        // checkout over a row that was already successfully recreated above.
        try {
            $this->activity->log(
                'system',
                null,
                'domain.carrier_product_recreated',
                'product',
                (int) $productId,
                'Recreated the "Domain Registration" carrier product — it was missing, so every standalone domain '
                . 'registration/transfer was failing with "temporarily unavailable". If this keeps happening, '
                . 'something is deleting it — check recent admin activity on the Products page.',
                null
            );
        } catch (\Throwable) {
        }

        return (int) $productId;
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

        // Every module's checkAvailability() carries its own reason for a
        // failure — the actual registrar-reported error, not a generic
        // string — but they don't all use the same key for it. Namecheap,
        // ResellerClub and Upperlink return it as 'status'; ConnectReseller
        // returns it as 'message'. This used to read only 'status', so a
        // ConnectReseller failure — of any kind, for any reason — always
        // fell straight through to the hardcoded "Availability check
        // failed.", discarding whatever ConnectReseller's API actually said
        // and leaving nothing for staff to diagnose. Checking both keys
        // means a future module drifting the same way degrades to the
        // generic message instead of silently losing real diagnostic detail.
        $reason = $result['status'] ?? $result['message'] ?? null;

        return [
            'success' => (bool) ($result['success'] ?? false),
            'available' => (bool) ($result['available'] ?? false),
            'message' => $reason !== null
                ? (string) $reason
                : (($result['success'] ?? false) ? 'Checked.' : 'Availability check failed.'),
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

        // The nameservers the buyer chose (or the admin's defaults, applied
        // at checkout) live on the domain row — send them along so the
        // registrar actually registers them instead of using its own
        // default. Previously this call never carried nameservers at all,
        // so every registration ended up with whatever the module guessed
        // (the local dev module wrote literal "ns1.codevault.invalid"
        // placeholders).
        $storedNameservers = json_decode((string) ($domain['nameservers'] ?? '[]'), true);
        $nameservers = is_array($storedNameservers)
            ? array_values(array_filter($storedNameservers, static fn ($ns) => trim((string) $ns) !== ''))
            : [];

        // Send the registrant/WHOIS contact so the registrar can complete the
        // registration. Upperlink (and Namecheap) reject an order with a
        // "Request validation error" when no contact is supplied. Prefer the
        // per-domain registrant chosen on the contact page (contact_data),
        // falling back to the owning client's own account details.
        $contact = $this->decodeContact($domain['contact_data'] ?? null);
        if ($contact === []) {
            $contact = $this->contactFromClient($client ?? []);
        }
        $contact = $this->expandContactForRegistrar($contact);

        $result = $module->register([
            'domain' => $domain['domain_name'],
            'years' => $years,
            'id_protection' => (bool) $domain['id_protection_enabled'],
            'registrar' => $config,
            'client' => $client ?? [],
            'registrarClientId' => $client['registrar_client_id'] ?? null,
            'nameservers' => $nameservers,
            'contacts' => $contact,
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

        // Validate domain grace and redemption limits.
        //
        // domains.tld is stored WITHOUT a leading dot (DomainRepository::create
        // takes everything after the first dot, so "foo.com" gives "com"),
        // while domain_pricing.tld is stored WITH one (".com"). Comparing them
        // raw never matched, so the lookup below always came back empty and the
        // grace/redemption limits were silently never enforced. Normalise to
        // the dotted form domain_pricing uses.
        $tld = strtolower(trim((string) ($domain['tld'] ?? '')));

        if ($tld === '') {
            $parts = explode('.', strtolower(trim((string) $domain['domain_name'])));

            if (count($parts) > 1) {
                array_shift($parts);
                $tld = implode('.', $parts);
            }
        }

        if ($tld !== '' && !str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
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
            // The full client record, not just the stored ID: a registrar that
            // has no local link yet (imported domain, or one registered before
            // this integration) can then resolve the customer from the client's
            // own details rather than failing the renewal outright.
            'client' => $client ?? [],
            'registrarClientId' => $client['registrar_client_id'] ?? null,
        ]);

        // Persist a customer ID the registrar resolved for us, exactly as
        // register()/transfer() do, so the link is repaired permanently and the
        // next renewal doesn't repeat the lookup.
        if (isset($result['registrarClientId']) && $client !== null) {
            $this->clients->updateRegistrarClientId((int) $client['id'], (string) $result['registrarClientId']);
        }

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

        $client = $this->clients->find((int) $domain['client_id']);

        $result = $module->getContactInfo([
            'domain' => $domain['domain_name'],
            'registrar' => $config,
            'registrarContactId' => $domain['registrar_contact_id'] ?? null,
            // Lets a registrar resolve the contact for a domain that has no
            // local link yet (imported, or predating this integration) instead
            // of reporting "no contact on file" for one that plainly exists.
            'registrarClientId' => $client['registrar_client_id'] ?? null,
            'client' => $client ?? [],
        ]);

        // Registrar round-trip succeeded and returned a contact — that's the
        // source of truth; display it verbatim (field names vary by registrar)
        // and cache a normalised copy locally for the form pre-fill and for
        // future fallbacks (plus capture any registrar contact id).
        if (($result['success'] ?? false) && !empty($result['contacts'])) {
            $normalized = $this->normalizeContact($result['contacts']);
            $this->domains->updateContactData($domainId, $normalized);

            if (isset($result['registrarContactId'])) {
                $this->domains->updateContactId($domainId, (string) $result['registrarContactId']);
            }

            return [
                'success' => true,
                'contacts' => $result['contacts'],
                'formContact' => $normalized,
                'contactId' => (int) ($domain['contact_id'] ?? 0),
                'source' => 'registrar',
                'message' => $result['message'] ?? '',
            ];
        }

        // The registrar couldn't provide a contact (domain not in the reseller
        // account, IP whitelist, empty response). Fall back to the locally
        // stored copy, or seed it from the owning client so the admin still
        // sees a usable, pre-fillable form instead of a blank one.
        $local = $this->decodeContact($domain['contact_data'] ?? null);
        if ($local === []) {
            $local = $this->contactFromClient($client ?? []);
        }
        $this->domains->updateContactData($domainId, $local);

        $notice = $result['success']
            ? 'The registrar returned no contact details for this domain — showing the locally stored contact instead.'
            : ($result['message'] ?? 'Could not load the contact from the registrar.');

        return ['success' => true, 'contacts' => $local, 'formContact' => $local, 'contactId' => (int) ($domain['contact_id'] ?? 0), 'source' => 'local', 'notice' => $notice];
    }

    /**
     * @param array<string, mixed> $contact
     * @param int|null $contactId the id of one of the client's saved contacts
     *        to use as the registrant ("on behalf of"), or null for a custom
     *        contact carried in $contact.
     */
    public function saveContactInfo(int $domainId, array $contact, ?int $contactId = null): array
    {
        [$domain, $module, $config, $error] = $this->context($domainId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $client = $this->clients->find((int) $domain['client_id']);
        $resolvedContactId = null;

        // A linked saved contact wins: resolve its full WHOIS details and use
        // them as the registrant (falling back to $contact for any field the
        // saved contact hasn't filled in).
        if ($contactId !== null && $client !== null) {
            $saved = $this->contacts->find($contactId);
            if ($saved !== null && (int) $saved['client_id'] === (int) $client['id']) {
                $resolved = $this->contactFromClientContact($saved);
                foreach ($contact as $key => $value) {
                    if (empty($resolved[$key] ?? '')) {
                        $resolved[$key] = $value;
                    }
                }
                $contact = $resolved;
                $resolvedContactId = (int) $saved['id'];
            }
        }

        // Always persist the edit locally first — a registrar that rejects the
        // push (domain not in the reseller account) must never lose the admin's
        // work; the local copy is what the contact page falls back to.
        $this->domains->updateContactData($domainId, $this->normalizeContact($contact));
        $this->domains->updateContactRef($domainId, $resolvedContactId);

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

        if ($result['success']) {
            return $result;
        }

        // Be honest with the admin: the edit is saved locally, but the
        // registrar rejected it — surface the EXACT reason and the likely fix.
        $message = trim((string) ($result['message'] ?? 'Unknown registrar error.'));
        $message .= " Your changes were saved locally, but NOT pushed to the registrar ({$domain['registrar_slug']}). "
            . 'This usually means the domain is not in your reseller account — register it via the API or contact the registrar to link it.';

        return ['success' => false, 'message' => $message];
    }

    /**
     * Map a registrar's arbitrary contact response onto the admin form's
     * field names so the page can both display it and pre-fill the editor.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function normalizeContact(array $raw): array
    {
        $aliases = [
            'name' => ['name', 'registrantName', 'fullname', 'firstname'],
            'email' => ['email', 'registrantEmail', 'emailaddress'],
            'company_name' => ['company_name', 'company', 'organisation', 'organization'],
            'address1' => ['address1', 'address', 'registrantaddress', 'street'],
            'city' => ['city', 'registrantcity', 'town'],
            'state' => ['state', 'registrantstate', 'province', 'region'],
            'postcode' => ['postcode', 'zip', 'postalcode', 'zipcode', 'registrantpostalcode'],
            'country' => ['country', 'countrycode', 'country_code'],
            'phone' => ['phone', 'telephone', 'phoneNumber', 'phoneno'],
        ];

        $out = [];

        foreach ($aliases as $field => $keys) {
            $out[$field] = '';
            foreach ($keys as $key) {
                if (isset($raw[$key]) && trim((string) $raw[$key]) !== '') {
                    $out[$field] = trim((string) $raw[$key]);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Seed a domain's contact from the owning client's stored details — the
     * registrant on every freshly-registered domain is the client, so this is
     * the correct default for a domain whose registrar can't be reached.
     *
     * @param array<string, mixed> $client
     * @return array<string, string>
     */
    public function contactFromClient(array $client): array
    {
        return [
            'name' => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
            'email' => (string) ($client['email'] ?? ''),
            'company_name' => (string) ($client['company_name'] ?? ''),
            'address1' => (string) ($client['address1'] ?? ''),
            'city' => (string) ($client['city'] ?? ''),
            'state' => (string) ($client['state'] ?? ''),
            'postcode' => (string) ($client['postcode'] ?? ''),
            'country' => (string) ($client['country'] ?? ''),
            'phone' => (string) ($client['phone'] ?? ''),
        ];
    }

    /**
     * Map a saved client contact (sub-account) onto the registrant form — used
     * when a domain is registered on behalf of that contact.
     *
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    public function contactFromClientContact(array $contact): array
    {
        return [
            'name' => (string) ($contact['name'] ?? ''),
            'email' => (string) ($contact['email'] ?? ''),
            'company_name' => (string) ($contact['company_name'] ?? ''),
            'address1' => (string) ($contact['address1'] ?? ''),
            'city' => (string) ($contact['city'] ?? ''),
            'state' => (string) ($contact['state'] ?? ''),
            'postcode' => (string) ($contact['postcode'] ?? ''),
            'country' => (string) ($contact['country'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
        ];
    }

    /** @return array<string, string> */
    private function decodeContact(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeContact($decoded) : [];
    }

    /**
     * Normalise a registrant contact into the flat field set registrar
     * register() calls read (Namecheap wants first_name/last_name rather than
     * one combined name; Upperlink/others accept the flat WHOIS fields) and
     * guarantee every key exists so the API never sees a missing field.
     *
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    private function expandContactForRegistrar(array $contact): array
    {
        $fullName = trim((string) ($contact['name'] ?? ''));

        if (empty($contact['first_name'] ?? '') && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            $contact['first_name'] = $parts[0] ?? '';
            $contact['last_name'] = $parts[1] ?? '';
        }

        foreach (['name', 'first_name', 'last_name', 'email', 'company_name', 'address1', 'city', 'state', 'postcode', 'country', 'phone'] as $key) {
            $contact[$key] ??= '';
        }

        return array_map('strval', $contact);
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

    /**
     * Re-point several domains at a different registrar. Refuses a slug that
     * doesn't match a configured registrar — the same guard the per-domain
     * updateRegistrar() applies.
     *
     * @param array<int, int> $ids
     */
    public function bulkUpdateRegistrar(array $ids, string $registrarSlug): int
    {
        $registrarSlug = trim($registrarSlug);
        if ($registrarSlug === '' || $this->registrars->findBySlug($registrarSlug) === null) {
            return 0;
        }
        return $this->domains->bulkUpdateRegistrar($ids, $registrarSlug);
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
