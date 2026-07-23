<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Namecheap domain reseller module, built from Namecheap's publicly
 * documented XML API — same "best documented shape, not live-verified"
 * caveat every registrar/gateway module in this codebase carries (see
 * UpperlinkRegistrarModule's docblock for the fuller disclaimer).
 *
 * Namecheap's API is genuinely XML in and XML out (unlike every other
 * registrar/gateway module here, which are all JSON) — decode() parses
 * the response with simplexml rather than json_decode. Auth is four
 * query params (ApiUser/ApiKey/UserName/ClientIp) on every request; the
 * ClientIp requirement in particular is real and unusual — Namecheap
 * only accepts API calls from IP addresses whitelisted in their control
 * panel, so config carries a client_ip field the admin has to keep in
 * sync with wherever this app is actually hosted.
 */
final class NamecheapRegistrarModule implements RegistrarModule
{
    private const LIVE_BASE_URL = 'https://api.namecheap.com/xml.response';
    private const SANDBOX_BASE_URL = 'https://api.sandbox.namecheap.com/xml.response';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Namecheap',
            'description' => 'Domain registration/transfer/renewal via the Namecheap XML API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'api_user' => ['type' => 'text', 'label' => 'API User', 'default' => ''],
            'api_key' => ['type' => 'password', 'label' => 'API Key', 'default' => ''],
            'username' => ['type' => 'text', 'label' => 'Namecheap Username', 'default' => ''],
            'client_ip' => ['type' => 'text', 'label' => 'Whitelisted Client IP (set in your Namecheap API settings)', 'default' => ''],
            'sandbox' => ['type' => 'text', 'label' => 'Sandbox Mode (1 = sandbox, blank = live)', 'default' => ''],
        ];
    }

    public function register(array $params): array
    {
        $registrar = $params['registrar'];
        $ns = implode(',', $params['nameservers'] ?? []);
        $contacts = (array) ($params['contacts'] ?? []);

        $query = array_merge([
            'DomainName' => $params['domain'],
            'Years' => (string) ($params['years'] ?? 1),
            'AddFreeWhoisguard' => ($params['id_protection'] ?? false) ? 'yes' : 'no',
            'WGEnabled' => ($params['id_protection'] ?? false) ? 'yes' : 'no',
        ], $this->registrantFields($contacts, 'Registrant'), $this->registrantFields($contacts, 'Tech'), $this->registrantFields($contacts, 'Admin'), $this->registrantFields($contacts, 'AuxBilling'));

        if ($ns !== '') {
            $query['Nameservers'] = $ns;
        }

        $response = $this->call($registrar, 'namecheap.domains.create', $query);

        return $this->toResult($response, 'Domain registered.');
    }

    public function transfer(array $params): array
    {
        $registrar = $params['registrar'];

        $response = $this->call($registrar, 'namecheap.domains.transfer.create', [
            'DomainName' => $params['domain'],
            'EPPCode' => $params['eppCode'] ?? '',
            'Years' => (string) ($params['years'] ?? 1),
        ]);

        return $this->toResult($response, 'Domain transfer initiated.');
    }

    public function renew(array $params): array
    {
        $registrar = $params['registrar'];

        $response = $this->call($registrar, 'namecheap.domains.renew', [
            'DomainName' => $params['domain'],
            'Years' => (string) ($params['years'] ?? 1),
        ]);

        return $this->toResult($response, 'Domain renewed.');
    }

    public function getNameservers(array $params): array
    {
        [$sld, $tld] = $this->splitDomain($params['domain']);
        $response = $this->call($params['registrar'], 'namecheap.domains.dns.getList', ['SLD' => $sld, 'TLD' => $tld]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'nameservers' => []];
        }

        $nsNode = $decoded['xml']->CommandResponse->DomainDNSGetListResult->Nameserver ?? [];
        $nameservers = [];
        foreach ($nsNode as $n) {
            $val = trim((string) $n);
            if ($val !== '') {
                $nameservers[] = $val;
            }
        }

        return ['success' => !empty($nameservers), 'nameservers' => $nameservers];
    }

    public function saveNameservers(array $params): array
    {
        [$sld, $tld] = $this->splitDomain($params['domain']);
        $rawNs = $params['nameservers'] ?? [];
        if (empty($rawNs)) {
            for ($i = 1; $i <= 6; $i++) {
                if (!empty($params["ns{$i}"])) {
                    $rawNs[] = (string) $params["ns{$i}"];
                }
            }
        }
        $nsList = array_values(array_filter(array_map('trim', (array) $rawNs)));
        $ns = implode(',', $nsList);

        $response = $this->call($params['registrar'], 'namecheap.domains.dns.setCustom', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameservers' => $ns,
        ]);

        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            $check = $this->getNameservers($params);
            if ($check['success'] && !empty($check['nameservers'])) {
                return ['success' => true, 'message' => 'Nameservers updated.', 'nameservers' => $check['nameservers']];
            }
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => 'Nameservers updated.', 'nameservers' => $nsList];
    }

    public function registerChildNs(array $params): array
    {
        [$sld, $tld] = $this->splitDomain($params['domain']);
        $ns = $params['hostname'];
        if (str_contains($ns, '.')) {
            $ns = explode('.', $ns)[0];
        }

        $response = $this->call($params['registrar'], 'namecheap.domains.ns.create', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $ns,
            'IP' => $params['ip'],
        ]);

        return $this->toResult($response, 'Private nameserver registered.');
    }

    public function deleteChildNs(array $params): array
    {
        [$sld, $tld] = $this->splitDomain($params['domain']);
        $ns = $params['hostname'];
        if (str_contains($ns, '.')) {
            $ns = explode('.', $ns)[0];
        }

        $response = $this->call($params['registrar'], 'namecheap.domains.ns.delete', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $ns,
        ]);

        return $this->toResult($response, 'Private nameserver deleted.');
    }

    public function getContactInfo(array $params): array
    {
        $response = $this->call($params['registrar'], 'namecheap.domains.getContacts', ['DomainName' => $params['domain']]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'contacts' => []];
        }

        $result = $decoded['xml']->CommandResponse->DomainContactsResult ?? null;
        $registrant = $result !== null ? (array) ($result->Registrant ?? []) : [];

        return ['success' => true, 'contacts' => $registrant];
    }

    public function saveContactInfo(array $params): array
    {
        $contacts = (array) ($params['contacts'] ?? []);
        $query = array_merge(
            ['DomainName' => $params['domain']],
            $this->registrantFields($contacts, 'Registrant'),
            $this->registrantFields($contacts, 'Tech'),
            $this->registrantFields($contacts, 'Admin'),
            $this->registrantFields($contacts, 'AuxBilling')
        );

        $response = $this->call($params['registrar'], 'namecheap.domains.setContacts', $query);

        return $this->toResult($response, 'Contact info updated.');
    }

    public function getRegistrarLock(array $params): array
    {
        $response = $this->call($params['registrar'], 'namecheap.domains.getRegistrarLock', ['DomainName' => $params['domain']]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'locked' => false];
        }

        $result = $decoded['xml']->CommandResponse->DomainGetRegistrarLockResult ?? null;
        $locked = $result !== null && strtolower((string) ($result['RegistrarLockStatus'] ?? 'false')) === 'true';

        return ['success' => true, 'locked' => $locked];
    }

    public function setRegistrarLock(array $params): array
    {
        $lock = (bool) ($params['lock'] ?? true);

        $response = $this->call($params['registrar'], 'namecheap.domains.setRegistrarLock', [
            'DomainName' => $params['domain'],
            'LockAction' => $lock ? 'LOCK' : 'UNLOCK',
        ]);

        $decoded = $this->decode($response);
        if (!$decoded['success']) {
            $check = $this->getRegistrarLock($params);
            if ($check['success'] && $check['locked'] === $lock) {
                return ['success' => true, 'message' => $lock ? 'Domain locked.' : 'Domain unlocked.'];
            }
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $lock ? 'Domain locked.' : 'Domain unlocked.'];
    }

    public function getEppCode(array $params): array
    {
        // 1. Direct namecheap.domains.getEPPCode call
        $response = $this->call($params['registrar'], 'namecheap.domains.getEPPCode', ['DomainName' => $params['domain']]);
        $decoded = $this->decode($response);

        if ($decoded['success'] && isset($decoded['xml']->CommandResponse)) {
            $res = $decoded['xml']->CommandResponse->DomainGetEPPCodeResult ?? ($decoded['xml']->CommandResponse->DomainGetEppCodeResult ?? null);
            if ($res !== null) {
                $code = (string) ($res['EPPCode'] ?? ($res['EppCode'] ?? ($res->EPPCode ?? ($res->EppCode ?? ($res['AuthCode'] ?? '')))));
                if ($code !== '') {
                    return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.'];
                }
            }
        }

        // 2. Fallback to namecheap.domains.getInfo
        $infoResponse = $this->call($params['registrar'], 'namecheap.domains.getInfo', ['DomainName' => $params['domain']]);
        $infoDecoded = $this->decode($infoResponse);

        if ($infoDecoded['success'] && isset($infoDecoded['xml']->CommandResponse->DomainGetInfoResult)) {
            $info = $infoDecoded['xml']->CommandResponse->DomainGetInfoResult;
            $code = (string) ($info['EPPCode'] ?? ($info->EPPCode ?? ($info['AuthCode'] ?? '')));
            if ($code !== '') {
                return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.'];
            }
        }

        // 3. Fallback: transfer.getInfo email trigger
        $transferResponse = $this->call($params['registrar'], 'namecheap.domains.transfer.getInfo', ['DomainName' => $params['domain']]);
        $transferDecoded = $this->decode($transferResponse);

        return [
            'success' => $transferDecoded['success'],
            'eppCode' => '',
            'message' => $transferDecoded['success'] ? 'Namecheap emails the transfer auth-code to the registrant contact.' : $transferDecoded['message'],
        ];
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
        [$sld, $tld] = $this->splitDomain($params['domain']);

        $val = $enabled ? 'true' : 'false';

        $response = $this->call($params['registrar'], 'namecheap.domains.setWhoisguard', [
            'SLD' => $sld,
            'TLD' => $tld,
            'IsEnabled' => $val,
            'Enable' => $val,
            'WGEnabled' => $val,
        ]);

        return $this->toResult($response, $enabled ? 'ID protection enabled.' : 'ID protection disabled.');
    }

    public function checkAvailability(array $params): array
    {
        $response = $this->call($params['registrar'], 'namecheap.domains.check', ['DomainList' => $params['domain']]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'available' => false, 'expiryDate' => null, 'status' => 'error'];
        }

        $results = $decoded['xml']->CommandResponse->DomainCheckResult ?? [];
        $available = false;

        foreach ($results as $result) {
            if (strtolower((string) ($result['Domain'] ?? '')) === strtolower((string) $params['domain'])) {
                $available = strtolower((string) ($result['Available'] ?? 'false')) === 'true';
                break;
            }
        }

        return ['success' => true, 'available' => $available, 'expiryDate' => null, 'status' => $available ? 'available' : 'unavailable'];
    }

    public function sync(array $params): array
    {
        $response = $this->call($params['registrar'], 'namecheap.domains.getInfo', ['DomainName' => $params['domain']]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'status' => null, 'expiryDate' => null];
        }

        $result = $decoded['xml']->CommandResponse->DomainGetInfoResult ?? null;
        $status = $result !== null ? (string) ($result['Status'] ?? '') : null;
        $expiry = $result !== null ? (string) ($result->DomainDetails->ExpiredDate ?? '') : null;

        return ['success' => true, 'status' => $status, 'expiryDate' => $expiry !== '' ? $expiry : null];
    }

    /** @param array<string, mixed> $registrar */
    private function call(array $registrar, string $command, array $query): array
    {
        $baseUrl = !empty($registrar['sandbox']) ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;

        $query = array_merge([
            'ApiUser' => (string) ($registrar['api_user'] ?? ''),
            'ApiKey' => (string) ($registrar['api_key'] ?? ''),
            'UserName' => (string) ($registrar['username'] ?? ''),
            'ClientIp' => (string) ($registrar['client_ip'] ?? ''),
            'Command' => $command,
        ], $query);

        return $this->http->request('GET', $baseUrl . '?' . http_build_query($query), []);
    }

    /** @return array{0: string, 1: string} [SLD, TLD] */
    private function splitDomain(string $domain): array
    {
        $firstDot = strpos($domain, '.');

        return $firstDot === false ? [$domain, 'com'] : [substr($domain, 0, $firstDot), substr($domain, $firstDot + 1)];
    }

    /**
     * Namecheap requires a full postal-address contact block per role
     * (Registrant/Tech/Admin/AuxBilling), each with an identical field set
     * prefixed by the role name — this maps this app's one flat $contacts
     * array onto all four required prefixes rather than asking the caller
     * to repeat itself four times.
     *
     * @param array<string, mixed> $contacts
     * @return array<string, string>
     */
    private function registrantFields(array $contacts, string $prefix): array
    {
        $map = [
            'FirstName' => $contacts['first_name'] ?? '',
            'LastName' => $contacts['last_name'] ?? '',
            'Address1' => $contacts['address1'] ?? '',
            'City' => $contacts['city'] ?? '',
            'StateProvince' => $contacts['state'] ?? '',
            'PostalCode' => $contacts['postcode'] ?? '',
            'Country' => $contacts['country'] ?? '',
            'Phone' => $contacts['phone'] ?? '',
            'EmailAddress' => $contacts['email'] ?? '',
        ];

        $fields = [];
        foreach ($map as $suffix => $value) {
            $fields["{$prefix}{$suffix}"] = (string) $value;
        }

        return $fields;
    }

    /** @param array{status: int, body: string} $response */
    private function toResult(array $response, string $successMessage): array
    {
        $decoded = $this->decode($response);

        return $decoded['success']
            ? ['success' => true, 'message' => $successMessage]
            : ['success' => false, 'message' => $decoded['message']];
    }

    /**
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, xml: ?\SimpleXMLElement}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the Namecheap API.', 'xml' => null];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response['body']);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return ['success' => false, 'message' => "Unexpected (non-XML) response (HTTP {$response['status']}).", 'xml' => null];
        }

        $status = (string) ($xml['Status'] ?? '');
        $success = $response['status'] === 200 && $status === 'OK';

        if ($success) {
            return ['success' => true, 'message' => 'OK', 'xml' => $xml];
        }

        $errorText = isset($xml->Errors->Error) ? (string) $xml->Errors->Error : "Namecheap API error (HTTP {$response['status']}).";

        return ['success' => false, 'message' => $errorText, 'xml' => $xml];
    }
}
