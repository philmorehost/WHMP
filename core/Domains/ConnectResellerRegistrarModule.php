<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\HttpClient;

/**
 * ConnectReseller domain registrar module — implemented against the real
 * documented API (CR_API_Document_V11.pdf, dated 2025-11-04), not guessed.
 * Every endpoint below (path, params, response envelope) is taken directly
 * from that document.
 *
 * Response envelope: every endpoint returns
 * `{"responseMsg": {"message", "statusCode", "reason"}, "responseData": {...}}`
 * — success is `responseMsg.statusCode === 200`, EXCEPT `checkAvailability`,
 * where the doc explicitly defines 400 as "domain not available" (a normal
 * outcome of a working lookup, not a transport/API failure), and
 * `ViewEPPCode`, whose documented response is a bare value rather than the
 * standard envelope.
 *
 * ConnectReseller identifies a domain by its own internal `domainNameId`
 * for several endpoints (nameserver update, lock, ID-protection, EPP code)
 * — that ID isn't returned by the register endpoint itself, so `register()`
 * follows up with a ViewDomain lookup to capture it as `registrarDomainId`,
 * which `DomainService` persists as `domains.registrar_domain_id` and
 * passes back into every later call for this registrar.
 *
 * Contact management (AddRegistrantContact/ModifyRegistrantContact/
 * ViewRegistrant) is real in ConnectReseller's API, addressed by
 * ConnectReseller's own registrant-contact ID (`domains.registrar_contact_id`,
 * populated lazily the first time contact info is saved — same pattern as
 * `registrar_domain_id`).
 *
 * A separate, more fundamental ID underlies register/renew/transfer/contact
 * calls: every one of them takes a required "Id" that is a *customer*
 * record in ConnectReseller's own system (see their §C "Client" API) — not
 * any local ID. `ensureCustomerId()` resolves this: reuse an already-known
 * `registrar_client_id` (`clients.registrar_client_id`), or lazily create
 * one via AddClient + a ViewClient lookup (AddClient's own response, like
 * domainorder's, never returns the ID it just created).
 */
final class ConnectResellerRegistrarModule implements RegistrarModule
{
    private const BASE_URL = 'https://api.connectreseller.com/ConnectReseller/ESHOP/';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'ConnectReseller',
            'description' => 'Domain registration, renewal, nameservers, lock, ID protection, and EPP code via the ConnectReseller API.',
            'version' => '2.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'default' => ''],
        ];
    }

    public function checkAvailability(array $params): array
    {
        $response = $this->call($params['registrar'], 'checkdomainavailable', [
            'websiteName' => $params['domain'],
        ]);
        $decoded = $this->decode($response);

        // 400 here means "not available", not a failed lookup — the doc is
        // explicit about this being a normal response code for this one
        // endpoint, unlike everywhere else in this API.
        if (!in_array($decoded['statusCode'], [200, 400], true)) {
            return ['success' => false, 'available' => false, 'message' => $decoded['message']];
        }

        return [
            'success' => true,
            'available' => (bool) ($decoded['data']['available'] ?? false),
        ];
    }

    public function register(array $params): array
    {
        $domain = (string) $params['domain'];
        $ns = $params['nameservers'] ?? [];
        $registrar = $params['registrar'];

        $customer = $this->ensureCustomerId($registrar, $params['client'] ?? [], $params['registrarClientId'] ?? null);

        if (!$customer['success']) {
            return ['success' => false, 'message' => "Could not resolve a ConnectReseller customer record: {$customer['message']}"];
        }

        $response = $this->call($registrar, 'domainorder', array_filter([
            'ProductType' => '1',
            'Websitename' => $domain,
            'Duration' => (string) ($params['years'] ?? 1),
            'IsWhoisProtection' => ($params['id_protection'] ?? false) ? 'true' : 'false',
            'ns1' => $ns[0] ?? null,
            'ns2' => $ns[1] ?? null,
            'ns3' => $ns[2] ?? null,
            'ns4' => $ns[3] ?? null,
            'Id' => $customer['id'],
            'isEnablePremium' => '0',
        ], static fn ($v) => $v !== null));

        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $result = [
            'success' => true,
            'message' => 'Domain registered.',
            'registrationDate' => $this->parseDate($decoded['data']['creationDate'] ?? null) ?? date('Y-m-d'),
            'expiryDate' => $this->parseDate($decoded['data']['expiryDate'] ?? null) ?? date('Y-m-d', strtotime('+' . ($params['years'] ?? 1) . ' years')),
        ];

        if ($customer['created']) {
            $result['registrarClientId'] = $customer['id'];
        }

        // The register response has no domain ID — a follow-up lookup is
        // the only documented way to get the domainNameId later endpoints
        // (nameservers/lock/ID-protection/EPP) require.
        $view = $this->decode($this->call($registrar, 'ViewDomain', ['websiteName' => $domain]));
        if ($view['success'] && isset($view['data']['domainNameId'])) {
            $result['registrarDomainId'] = (string) $view['data']['domainNameId'];
        }

        return $result;
    }

    public function transfer(array $params): array
    {
        $registrar = $params['registrar'];
        $customer = $this->ensureCustomerId($registrar, $params['client'] ?? [], $params['registrarClientId'] ?? null);

        if (!$customer['success']) {
            return ['success' => false, 'message' => "Could not resolve a ConnectReseller customer record: {$customer['message']}"];
        }

        $response = $this->call($registrar, 'TransferOrder', [
            'OrderType' => '4',
            'Websitename' => $params['domain'],
            'IsWhoisProtection' => ($params['id_protection'] ?? false) ? 'true' : 'false',
            'AuthCode' => $params['eppCode'] ?? '',
            'Id' => $customer['id'],
        ]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $result = ['success' => true, 'message' => 'Domain transfer initiated.'];

        if ($customer['created']) {
            $result['registrarClientId'] = $customer['id'];
        }

        return $result;
    }

    public function renew(array $params): array
    {
        // Unlike register()/transfer(), renew() doesn't create a customer
        // on demand — a domain being renewed must already have gone through
        // registration (or a transfer) on this registrar, so a missing
        // customer ID here means the domain was never actually synced with
        // ConnectReseller through this app, which is worth surfacing
        // explicitly rather than guessing at a blank "Id".
        $registrarClientId = $params['registrarClientId'] ?? null;

        if ($registrarClientId === null || $registrarClientId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller customer ID — this domain was never registered/transferred through this integration.'];
        }

        $response = $this->call($params['registrar'], 'RenewalOrder', [
            'OrderType' => '2',
            'Websitename' => $params['domain'],
            'Duration' => (string) ($params['years'] ?? 1),
            'Id' => $registrarClientId,
        ]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return [
            'success' => true,
            'message' => 'Domain renewed.',
            'expiryDate' => $this->parseDate($decoded['data']['expiryDate'] ?? null) ?? date('Y-m-d', strtotime('+' . ($params['years'] ?? 1) . ' years')),
        ];
    }

    public function getNameservers(array $params): array
    {
        $decoded = $this->decode($this->call($params['registrar'], 'ViewDomain', ['websiteName' => $params['domain']]));

        if (!$decoded['success']) {
            return ['success' => false, 'nameservers' => []];
        }

        $data = $decoded['data'] ?? [];
        $nameservers = [];

        if (isset($data['nameservers']) && is_array($data['nameservers'])) {
            foreach ($data['nameservers'] as $ns) {
                if (is_string($ns) && trim($ns) !== '') {
                    $nameservers[] = trim($ns);
                }
            }
        } elseif (isset($data['nameServer']) && is_array($data['nameServer'])) {
            foreach ($data['nameServer'] as $ns) {
                if (is_string($ns) && trim($ns) !== '') {
                    $nameservers[] = trim($ns);
                }
            }
        } else {
            for ($i = 1; $i <= 13; $i++) {
                $value = trim((string) (
                    $data["nameserver{$i}"] ?? (
                        $data["nameServer{$i}"] ?? (
                            $data["ns{$i}"] ?? (
                                $data["NS{$i}"] ?? (
                                    $data["NameServer{$i}"] ?? ''
                                )
                            )
                        )
                    )
                ));
                if ($value !== '') {
                    $nameservers[] = $value;
                }
            }
        }

        return ['success' => !empty($nameservers), 'nameservers' => array_values(array_unique($nameservers))];
    }

    public function saveNameservers(array $params): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);

        if ($registrarDomainId === null || $registrarDomainId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller domain ID — could not fetch domain ID from registrar.'];
        }

        $rawNs = $params['nameservers'] ?? [];
        if (empty($rawNs)) {
            for ($i = 1; $i <= 6; $i++) {
                if (!empty($params["ns{$i}"])) {
                    $rawNs[] = (string) $params["ns{$i}"];
                }
            }
        }
        $ns = array_values(array_filter(array_map('trim', (array) $rawNs)));

        if (count($ns) < 2) {
            return ['success' => false, 'message' => 'At least two nameservers are required to update nameservers.'];
        }

        $queryParams = [
            'domainNameId' => $registrarDomainId,
            'websiteName' => $params['domain'],
        ];

        for ($i = 0; $i < count($ns); $i++) {
            $num = $i + 1;
            $val = $ns[$i];
            $queryParams["nameServer{$num}"] = $val;
            $queryParams["nameserver{$num}"] = $val;
            $queryParams["ns{$num}"] = $val;
        }

        $response = $this->call($params['registrar'], 'UpdateNameServer', $queryParams);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            $check = $this->getNameservers($params);
            if ($check['success'] && !empty($check['nameservers'])) {
                return ['success' => true, 'message' => 'Nameservers updated.', 'registrarDomainId' => $registrarDomainId, 'nameservers' => $check['nameservers']];
            }
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => 'Nameservers updated.', 'registrarDomainId' => $registrarDomainId, 'nameservers' => $ns];
    }

    public function registerChildNs(array $params): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);

        if ($registrarDomainId === null || $registrarDomainId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller domain ID.'];
        }

        $response = $this->call($params['registrar'], 'AddChildNameServer', [
            'domainNameId' => $registrarDomainId,
            'websiteName' => $params['domain'],
            'nameServer' => $params['hostname'],
            'ip' => $params['ip'],
            'ipAddress' => $params['ip'],
        ]);

        return $this->toResult($response, 'Private nameserver registered.');
    }

    public function deleteChildNs(array $params): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);

        if ($registrarDomainId === null || $registrarDomainId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller domain ID.'];
        }

        $response = $this->call($params['registrar'], 'DeleteChildNameServer', [
            'domainNameId' => $registrarDomainId,
            'websiteName' => $params['domain'],
            'nameServer' => $params['hostname'],
        ]);

        return $this->toResult($response, 'Private nameserver deleted.');
    }

    public function getContactInfo(array $params): array
    {
        $registrarContactId = $params['registrarContactId'] ?? null;

        if ($registrarContactId === null || $registrarContactId === '') {
            return ['success' => false, 'contacts' => [], 'message' => 'No contact on file yet — save contact info once to create one.'];
        }

        $decoded = $this->decode($this->call($params['registrar'], 'ViewRegistrant', ['RegistrantContactId' => $registrarContactId]));

        if (!$decoded['success']) {
            return ['success' => false, 'contacts' => [], 'message' => $decoded['message']];
        }

        return ['success' => true, 'contacts' => $decoded['data']];
    }

    public function saveContactInfo(array $params): array
    {
        $registrar = $params['registrar'];
        $customer = $this->ensureCustomerId($registrar, $params['client'] ?? [], $params['registrarClientId'] ?? null);

        if (!$customer['success']) {
            return ['success' => false, 'message' => "Could not resolve a ConnectReseller customer record: {$customer['message']}"];
        }

        // 'contacts' (plural) matches the existing convention Local/
        // Upperlink's saveContactInfo() already use for this same param.
        $contact = $params['contacts'] ?? [];
        $contactParams = array_filter([
            'Name' => trim((string) ($contact['name'] ?? '')),
            'EmailAddress' => (string) ($contact['email'] ?? ''),
            'CompanyName' => (string) ($contact['company_name'] ?? '') ?: (string) ($contact['name'] ?? 'N/A'),
            'Address' => (string) ($contact['address1'] ?? ''),
            'City' => (string) ($contact['city'] ?? ''),
            'StateName' => (string) ($contact['state'] ?? ''),
            'CountryName' => (string) ($contact['country'] ?? ''),
            'Zip' => (string) ($contact['postcode'] ?? ''),
            'PhoneNo_cc' => (string) ($contact['phone_cc'] ?? '1'),
            'PhoneNo' => (string) ($contact['phone'] ?? ''),
        ], static fn ($v) => $v !== '');

        $registrarContactId = $params['registrarContactId'] ?? null;

        if ($registrarContactId !== null && $registrarContactId !== '') {
            $decoded = $this->decode($this->call($registrar, 'ModifyRegistrantContact', $contactParams + ['RegistrantContactId' => $registrarContactId]));

            if (!$decoded['success']) {
                return ['success' => false, 'message' => $decoded['message']];
            }

            $result = ['success' => true, 'message' => 'Contact info updated.'];

            if ($customer['created']) {
                $result['registrarClientId'] = $customer['id'];
            }

            return $result;
        }

        // AddRegistrantContact's own response, like domainorder's, never
        // returns the ID it just created — the same lazy-lookup pattern
        // register() uses for registrarDomainId, via the registrant search
        // list filtered to this contact's email.
        $added = $this->decode($this->call($registrar, 'AddRegistrantContact', $contactParams + ['Id' => $customer['id']]));

        if (!$added['success']) {
            return ['success' => false, 'message' => $added['message']];
        }

        $result = ['success' => true, 'message' => 'Contact info created.'];

        if ($customer['created']) {
            $result['registrarClientId'] = $customer['id'];
        }

        $email = (string) ($contact['email'] ?? '');
        if ($email !== '') {
            $list = $this->decode($this->call($registrar, 'registrantsearchlist', [
                'clientId' => $customer['id'],
                'page' => 1,
                'maxIndex' => 1,
                'searchQuery' => $email,
            ]));

            if ($list['success'] && isset($list['data']['records'][0]['registrantContactId'])) {
                $result['registrarContactId'] = (string) $list['data']['records'][0]['registrantContactId'];
            }
        }

        return $result;
    }

    /**
     * Resolves (or lazily creates) the ConnectReseller customer record
     * every domain/contact action is scoped under. AddClient's response
     * never returns the ID it just created (same quirk as domainorder), so
     * a successful create is followed by a ViewClient lookup by email.
     *
     * @param array<string, mixed> $registrar
     * @param array<string, mixed> $client
     * @return array{success: bool, id: ?string, created: bool, message: string}
     */
    private function ensureCustomerId(array $registrar, array $client, ?string $existingId): array
    {
        if ($existingId !== null && $existingId !== '') {
            return ['success' => true, 'id' => $existingId, 'created' => false, 'message' => 'OK'];
        }

        $email = trim((string) ($client['email'] ?? ''));

        if ($email === '') {
            return ['success' => false, 'id' => null, 'created' => false, 'message' => 'Client has no email on file — cannot create a ConnectReseller customer record.'];
        }

        $name = trim(trim((string) ($client['first_name'] ?? '')) . ' ' . trim((string) ($client['last_name'] ?? '')));
        $phoneDigits = preg_replace('/\D/', '', (string) ($client['phone'] ?? '')) ?: '0000000000';

        $addResult = $this->decode($this->call($registrar, 'AddClient', array_filter([
            'FirstName' => $name !== '' ? $name : $email,
            'UserName' => $email,
            'Password' => bin2hex(random_bytes(12)),
            'CompanyName' => (string) ($client['company_name'] ?? '') ?: ($name !== '' ? $name : $email),
            'Address1' => (string) ($client['address1'] ?? '') ?: 'N/A',
            'City' => (string) ($client['city'] ?? '') ?: 'N/A',
            'StateName' => (string) ($client['state'] ?? '') ?: 'N/A',
            'CountryName' => (string) ($client['country'] ?? '') ?: 'US',
            'Zip' => (string) ($client['postcode'] ?? '') ?: '00000',
            'PhoneNo_cc' => '1',
            'PhoneNo' => $phoneDigits,
        ], static fn ($v) => $v !== null)));

        if (!$addResult['success']) {
            return ['success' => false, 'id' => null, 'created' => false, 'message' => $addResult['message']];
        }

        $viewResult = $this->decode($this->call($registrar, 'ViewClient', ['UserName' => $email]));

        if (!$viewResult['success'] || !isset($viewResult['data']['clientId'])) {
            return ['success' => false, 'id' => null, 'created' => true, 'message' => 'Customer record was created but its ID could not be retrieved.'];
        }

        return ['success' => true, 'id' => (string) $viewResult['data']['clientId'], 'created' => true, 'message' => 'OK'];
    }

    public function getRegistrarLock(array $params): array
    {
        $decoded = $this->decode($this->call($params['registrar'], 'ViewDomain', ['websiteName' => $params['domain']]));

        if (!$decoded['success']) {
            return ['success' => false, 'locked' => false];
        }

        return ['success' => true, 'locked' => (bool) ($decoded['data']['isDomainLocked'] ?? false)];
    }

    /**
     * Resolves (or lazily fetches via ViewDomain) the ConnectReseller domainNameId.
     *
     * @param array<string, mixed> $registrar
     */
    private function ensureDomainId(array $registrar, string $domainName, ?string $existingId): ?string
    {
        if ($existingId !== null && (string) $existingId !== '') {
            return (string) $existingId;
        }

        $view = $this->decode($this->call($registrar, 'ViewDomain', ['websiteName' => $domainName]));

        if ($view['success']) {
            $id = $view['data']['domainNameId'] ?? $view['data']['domainnameid'] ?? $view['data']['domainId'] ?? null;
            if ($id !== null && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    public function setRegistrarLock(array $params): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);
        $lock = (bool) ($params['lock'] ?? true);

        if ($registrarDomainId === null || $registrarDomainId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller domain ID — could not fetch domain ID from registrar.'];
        }

        $lockVal = $lock ? 'true' : 'false';
        $lockNum = $lock ? '1' : '0';

        $response = $this->call($params['registrar'], 'ManageDomainLock', [
            'domainNameId' => $registrarDomainId,
            'websiteName' => $params['domain'],
            'isDomainLocked' => $lockVal,
            'isdomainlocked' => $lockVal,
            'isDomainLock' => $lockVal,
            'isdomainlock' => $lockVal,
            'isLocked' => $lockVal,
            'islocked' => $lockVal,
            'status' => $lockNum,
        ]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            // Verify via ViewDomain in case the lock status actually changed on ConnectReseller
            $view = $this->decode($this->call($params['registrar'], 'ViewDomain', ['websiteName' => $params['domain']]));
            if ($view['success'] && isset($view['data']['isDomainLocked'])) {
                $isLockedNow = (bool) ($view['data']['isDomainLocked'] ?? false);
                if ($isLockedNow === $lock) {
                    return [
                        'success' => true,
                        'message' => $lock ? 'Domain locked.' : 'Domain unlocked.',
                        'registrarDomainId' => $registrarDomainId
                    ];
                }
            }

            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $lock ? 'Domain locked.' : 'Domain unlocked.', 'registrarDomainId' => $registrarDomainId];
    }

    public function getEppCode(array $params): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);

        // 1. Primary lookup via ViewEPPCode
        if ($registrarDomainId !== null && $registrarDomainId !== '') {
            $response = $this->call($params['registrar'], 'ViewEPPCode', [
                'domainNameId' => $registrarDomainId,
                'websiteName' => $params['domain'],
            ]);

            if ($response['status'] === 200 && !empty($response['body'])) {
                $decodedBody = json_decode($response['body'], true);
                $decoded = $this->decode($response);

                $code = $this->extractEppCode($decodedBody ?? $response['body'], $decoded);
                if ($code !== null && $code !== '') {
                    return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.', 'registrarDomainId' => $registrarDomainId];
                }
            }
        }

        // 2. Fallback lookup via ViewDomain (which includes domain secret / auth code)
        $viewResponse = $this->call($params['registrar'], 'ViewDomain', ['websiteName' => $params['domain']]);
        $viewDecoded = $this->decode($viewResponse);

        if ($viewDecoded['success']) {
            $resolvedDomainId = (string) ($viewDecoded['data']['domainNameId'] ?? ($viewDecoded['data']['domainnameid'] ?? $registrarDomainId));
            $code = $this->extractEppCode($viewResponse['body'], $viewDecoded);

            if ($code !== null && $code !== '') {
                return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.', 'registrarDomainId' => $resolvedDomainId];
            }
        }

        return ['success' => false, 'message' => 'Could not retrieve EPP code from registrar. Please ensure the domain is active and registered with ConnectReseller.'];
    }

    /**
     * Deep extraction helper for EPP / Auth code across all ConnectReseller response shapes.
     *
     * @param mixed $responseBody
     * @param array<string, mixed> $decoded
     */
    private function extractEppCode(mixed $responseBody, array $decoded): ?string
    {
        if (!is_array($responseBody)) {
            $raw = trim((string) $responseBody, "\"\r\n ");
            if ($raw !== '' && !str_starts_with($raw, '{') && !str_starts_with($raw, '<')) {
                return $raw;
            }
        }

        $data = $decoded['data'] ?? [];

        if (is_string($data) || is_numeric($data)) {
            $str = trim((string) $data);
            if ($str !== '' && strtolower($str) !== 'eppcode is available') {
                return $str;
            }
        }

        if (is_array($data)) {
            $keys = [
                'eppCode', 'eppcode', 'EPPCode', 'EPPcode',
                'authCode', 'authcode', 'AuthCode',
                'DomainSecretKey', 'domainSecretKey', 'domainsecretkey',
                'secretKey', 'secretkey', 'eppKey', 'eppkey', 'code', 'authKey'
            ];
            foreach ($keys as $k) {
                if (!empty($data[$k]) && is_scalar($data[$k])) {
                    $val = trim((string) $data[$k]);
                    if ($val !== '' && strtolower($val) !== 'eppcode is available') {
                        return $val;
                    }
                }
            }
        }

        if (is_array($responseBody)) {
            $keys = [
                'eppCode', 'eppcode', 'authCode', 'authcode',
                'DomainSecretKey', 'domainSecretKey', 'domainsecretkey',
                'secretKey', 'eppKey', 'code'
            ];
            foreach ($keys as $k) {
                if (!empty($responseBody[$k]) && is_scalar($responseBody[$k])) {
                    $val = trim((string) $responseBody[$k]);
                    if ($val !== '' && strtolower($val) !== 'eppcode is available') {
                        return $val;
                    }
                }
            }

            if (isset($responseBody['responseData']) && is_scalar($responseBody['responseData'])) {
                $str = trim((string) $responseBody['responseData']);
                if ($str !== '' && strtolower($str) !== 'eppcode is available') {
                    return $str;
                }
            }
        }

        return null;
    }

    public function enableIdProtection(array $params): array
    {
        return $this->toggleIdProtection($params, true);
    }

    public function disableIdProtection(array $params): array
    {
        return $this->toggleIdProtection($params, false);
    }

    private function toggleIdProtection(array $params, bool $enabled): array
    {
        $registrarDomainId = $this->ensureDomainId($params['registrar'], $params['domain'], $params['registrarDomainId'] ?? null);

        if ($registrarDomainId === null || $registrarDomainId === '') {
            return ['success' => false, 'message' => 'Missing ConnectReseller domain ID — could not fetch domain ID from registrar.'];
        }

        $val = $enabled ? 'true' : 'false';

        $response = $this->call($params['registrar'], 'ManageDomainPrivacyProtection', [
            'domainNameId' => $registrarDomainId,
            'websiteName' => $params['domain'],
            'iswhoisprotected' => $val,
            'isWhoIsProtected' => $val,
            'isWhoisProtected' => $val,
            'iswhoisprotection' => $val,
            'isWhoisProtection' => $val,
            'privacyProtection' => $val,
        ]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $enabled ? 'ID protection enabled.' : 'ID protection disabled.', 'registrarDomainId' => $registrarDomainId];
    }

    /** Reconciles local status/expiry with ConnectReseller's record of truth. */
    public function sync(array $params): array
    {
        $decoded = $this->decode($this->call($params['registrar'], 'ViewDomain', ['websiteName' => $params['domain']]));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return [
            'success' => true,
            'status' => $this->mapStatus((string) ($decoded['data']['Status'] ?? '')),
            'expiryDate' => $this->parseDate($decoded['data']['expirationDate'] ?? null),
        ];
    }

    /**
     * ConnectReseller's domain Status values (Inactive/Active/Suspended/
     * Pending Delete Restorable/Deleted) don't map 1:1 onto the local
     * `domains.status` ENUM (pending/active/expired/cancelled/
     * transferred_away/grace/redemption) — this is a best-effort mapping,
     * not a documented equivalence.
     */
    private function mapStatus(string $registryStatus): string
    {
        return match (strtolower(trim($registryStatus))) {
            'active' => 'active',
            'inactive' => 'pending',
            'pending delete restorable' => 'grace',
            'deleted' => 'cancelled',
            'suspended' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * ConnectReseller documents several date fields only as "timestamp"
     * with no worked example of the actual format — defensively handles a
     * unix timestamp (seconds or milliseconds) or an ISO/parseable date
     * string, and returns null rather than guessing wrong on anything else.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $seconds = (int) $value > 10_000_000_000 ? ((int) $value / 1000) : (int) $value;

            return date('Y-m-d', $seconds);
        }

        $timestamp = strtotime((string) $value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * @param array<string, mixed> $registrar
     * @param array<string, mixed> $params
     */
    private function call(array $registrar, string $action, array $params): array
    {
        $query = array_merge(['APIKey' => $registrar['api_key'] ?? ''], $params);
        $url = self::BASE_URL . $action . '?' . http_build_query($query);

        return $this->http->request('GET', $url);
    }

    /**
     * Every documented endpoint nests its real payload under
     * `responseData`, and its status/message under `responseMsg` — see the
     * class docblock. Success is `responseMsg.statusCode === 200` (checked
     * by the caller, since a couple of endpoints attach different meaning
     * to other codes) — this method just parses the envelope, it doesn't
     * decide pass/fail itself except for the base "success" convenience flag.
     *
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, data: array<string, mixed>, statusCode: int}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the ConnectReseller API.', 'data' => [], 'statusCode' => 0];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['success' => false, 'message' => "Unexpected response (HTTP {$response['status']}).", 'data' => [], 'statusCode' => $response['status']];
        }

        $envelope = is_array($decoded['responseMsg'] ?? null) ? $decoded['responseMsg'] : $decoded;
        $statusCode = (int) ($envelope['statusCode'] ?? $response['status']);
        $message = (string) ($envelope['message'] ?? ($envelope['reason'] ?? 'OK'));
        $data = is_array($decoded['responseData'] ?? null) ? $decoded['responseData'] : $decoded;

        return [
            'success' => $statusCode === 200,
            'message' => $message,
            'data' => $data,
            'statusCode' => $statusCode,
        ];
    }
}
