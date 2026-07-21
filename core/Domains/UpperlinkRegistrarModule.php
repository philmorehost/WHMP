<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Upperlink domain reseller module, built directly from the API spec
 * provided (endpoint, action list, and the exact HMAC auth algorithm).
 *
 * CONFIDENCE NOTE — read before trusting this in production: the spec
 * given fully documents every REQUEST (endpoint path, HTTP method, param
 * names) and the auth scheme, so those are implemented exactly as
 * specified. It does NOT document the RESPONSE shape anywhere (no example
 * JSON body was provided for any action). decode() below is a best-effort
 * guess at a conventional `{status, message, data}`-style response and
 * WILL likely need adjusting once tested against the real API or once
 * response examples are available — this is a materially lower-confidence
 * module than CpanelProvisioningModule (which was verified live) or even
 * the request-side-only ConnectReseller module (whose docs at least came
 * with worked examples).
 */
final class UpperlinkRegistrarModule implements RegistrarModule
{
    private const BASE_URL = 'https://client.upperlink.ng/clients/modules/addons/DomainsReseller/api/index.php';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Upperlink',
            'description' => 'Domain registration/transfer/renewal via the Upperlink reseller API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'email' => ['type' => 'text', 'label' => 'Reseller Client Email (WHMCS-registered)', 'default' => ''],
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'default' => ''],
        ];
    }

    public function register(array $params): array
    {
        $response = $this->post($params['registrar'], '/order/domains/register', [
            'domain' => $params['domain'],
            'regperiod' => (string) ($params['years'] ?? 1),
            'nameservers' => $this->nameserverPayload($params['nameservers'] ?? []),
            'contacts' => $params['contacts'] ?? [],
            'addons' => [
                'dnsmanagement' => 0,
                'emailforwarding' => 0,
                'idprotection' => ($params['id_protection'] ?? false) ? 1 : 0,
            ],
        ]);

        return $this->toResult($response, 'Domain registered.');
    }

    public function transfer(array $params): array
    {
        $response = $this->post($params['registrar'], '/order/domains/transfer', [
            'domain' => $params['domain'],
            'eppcode' => $params['eppCode'] ?? '',
            'regperiod' => (string) ($params['years'] ?? 1),
            'nameservers' => $this->nameserverPayload($params['nameservers'] ?? []),
            'contacts' => $params['contacts'] ?? [],
        ]);

        return $this->toResult($response, 'Domain transfer initiated.');
    }

    public function renew(array $params): array
    {
        $response = $this->post($params['registrar'], '/order/domains/renew', [
            'domain' => $params['domain'],
            'regperiod' => (string) ($params['years'] ?? 1),
        ]);

        return $this->toResult($response, 'Domain renewed.');
    }

    public function getNameservers(array $params): array
    {
        $response = $this->get($params['registrar'], "/domains/{$this->enc($params['domain'])}/nameservers");
        $decoded = $this->decode($response);

        return ['success' => $decoded['success'], 'nameservers' => $decoded['data']['nameservers'] ?? []];
    }

    public function saveNameservers(array $params): array
    {
        $ns = $params['nameservers'] ?? [];
        $response = $this->post($params['registrar'], "/domains/{$this->enc($params['domain'])}/nameservers", array_filter([
            'domain' => $params['domain'],
            'ns1' => $ns[0] ?? null,
            'ns2' => $ns[1] ?? null,
            'ns3' => $ns[2] ?? null,
            'ns4' => $ns[3] ?? null,
            'ns5' => $ns[4] ?? null,
        ]));

        return $this->toResult($response, 'Nameservers updated.');
    }

    public function getContactInfo(array $params): array
    {
        $response = $this->get($params['registrar'], "/domains/{$this->enc($params['domain'])}/contact");
        $decoded = $this->decode($response);

        return ['success' => $decoded['success'], 'contacts' => $decoded['data'] ?? []];
    }

    public function saveContactInfo(array $params): array
    {
        $response = $this->post($params['registrar'], "/domains/{$this->enc($params['domain'])}/contact", [
            'domain' => $params['domain'],
            'contactdetails' => $params['contacts'] ?? [],
        ]);

        return $this->toResult($response, 'Contact info updated.');
    }

    public function getRegistrarLock(array $params): array
    {
        $response = $this->get($params['registrar'], "/domains/{$this->enc($params['domain'])}/lock");
        $decoded = $this->decode($response);

        return ['success' => $decoded['success'], 'locked' => (bool) ($decoded['data']['lockstatus'] ?? false)];
    }

    public function setRegistrarLock(array $params): array
    {
        $lock = (bool) ($params['lock'] ?? true);
        $response = $this->post($params['registrar'], "/domains/{$this->enc($params['domain'])}/lock", [
            'domain' => $params['domain'],
            'lockstatus' => $lock ? '1' : '0',
        ]);

        return $this->toResult($response, $lock ? 'Domain locked.' : 'Domain unlocked.');
    }

    public function getEppCode(array $params): array
    {
        $response = $this->get($params['registrar'], "/domains/{$this->enc($params['domain'])}/eppcode");
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'eppCode' => $decoded['data']['eppcode'] ?? ($decoded['data']['epp'] ?? ''), 'message' => 'EPP code retrieved.'];
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
        $response = $this->post($params['registrar'], "/domains/{$this->enc($params['domain'])}/protectid", [
            'domain' => $params['domain'],
            'status' => $enabled ? 1 : 0,
        ]);

        return $this->toResult($response, $enabled ? 'ID protection enabled.' : 'ID protection disabled.');
    }

    public function checkAvailability(array $params): array
    {
        $domain = $params['domain'];
        $parts = explode('.', $domain, 2);
        $sld = $parts[0] ?? $domain;
        $tld = isset($parts[1]) ? '.' . $parts[1] : '';

        $response = $this->post($params['registrar'], '/domains/lookup', [
            'searchTerm' => $domain,
            'sld' => $sld,
            'tld' => $tld,
            'domain' => $domain,
        ]);

        $decoded = $this->decode($response);

        // Parse Upperlink response variations (available, is_available, status == 'available')
        $data = $decoded['data'];
        $isAvailable = false;

        if (isset($data['available'])) {
            $isAvailable = (bool) $data['available'];
        } elseif (isset($data['is_available'])) {
            $isAvailable = (bool) $data['is_available'];
        } elseif (isset($data['status'])) {
            $isAvailable = strtolower((string) $data['status']) === 'available';
        } elseif (isset($decoded['available'])) {
            $isAvailable = (bool) $decoded['available'];
        } elseif (isset($decoded['status']) && strtolower((string) $decoded['status']) === 'available') {
            $isAvailable = true;
        }

        // Socket WHOIS check fallback if registrar API lookup fails or returns unexpected format
        if (!$decoded['success'] && function_exists('fsockopen')) {
            $whoisCheck = $this->socketWhoisCheck($domain);
            if ($whoisCheck !== null) {
                return $whoisCheck;
            }
        }

        return [
            'success' => $decoded['success'],
            'available' => $isAvailable,
            'expiryDate' => $data['expiryDate'] ?? ($decoded['expiryDate'] ?? null),
            'status' => $decoded['message'] !== '' ? $decoded['message'] : ($decoded['success'] ? 'checked' : 'error'),
        ];
    }

    private function socketWhoisCheck(string $domain): ?array
    {
        $domainLower = strtolower($domain);

        // Check if domain is a .ng extension (.ng, .com.ng, .org.ng, .net.ng, .gov.ng, .edu.ng, .name.ng, .mobi.ng, .sch.ng, .i.ng)
        if (preg_match('/\.ng$/i', $domainLower)) {
            $url = 'https://whois.nic.net.ng/domain/' . urlencode($domainLower);
            $response = $this->http->request('GET', $url, []);
            
            // WHMCS dist.whois.json rule for .ng: HTTP 404 or "404" in body means available
            $isAvailable = $response['status'] === 404 || str_contains($response['body'], '404') || str_contains(strtolower($response['body']), 'not found');
            
            return [
                'success' => true,
                'available' => $isAvailable,
                'expiryDate' => null,
                'status' => $isAvailable ? 'Available' : 'Registered',
            ];
        }

        $parts = explode('.', $domainLower);
        $tld = '.' . end($parts);

        $whoisServers = [
            '.com' => 'whois.verisign-grs.com',
            '.net' => 'whois.verisign-grs.com',
            '.org' => 'whois.pir.org',
            '.info' => 'whois.afilias.net',
            '.biz' => 'whois.biz',
            '.co' => 'whois.nic.co',
            '.io' => 'whois.nic.io',
            '.me' => 'whois.nic.me',
            '.us' => 'whois.nic.us',
            '.uk' => 'whois.nic.uk',
            '.co.uk' => 'whois.nic.uk',
        ];

        $server = $whoisServers[$tld] ?? null;
        if ($server === null) {
            return null;
        }

        $fp = @fsockopen($server, 43, $errno, $errstr, 5);
        if (!$fp) {
            return null;
        }

        fputs($fp, $domain . "\r\n");
        $out = '';
        while (!feof($fp)) {
            $out .= fgets($fp, 128);
        }
        fclose($fp);

        $outLower = strtolower($out);
        $notFoundStrings = [
            'no match',
            'not found',
            'no entries found',
            'domain not found',
            'no data found',
            'nothing found',
        ];

        $available = false;
        foreach ($notFoundStrings as $pattern) {
            if (str_contains($outLower, $pattern)) {
                $available = true;
                break;
            }
        }

        return [
            'success' => true,
            'available' => $available,
            'expiryDate' => null,
            'status' => $available ? 'Available' : 'Registered',
        ];
    }

    public function sync(array $params): array
    {
        $response = $this->post($params['registrar'], "/domains/{$this->enc($params['domain'])}/sync", [
            'domain' => $params['domain'],
        ]);

        $decoded = $this->decode($response);

        return [
            'success' => $decoded['success'],
            'status' => $decoded['data']['status'] ?? null,
            'expiryDate' => $decoded['data']['expiryDate'] ?? null,
        ];
    }

    /** @param array<string, mixed> $registrar */
    private function get(array $registrar, string $path): array
    {
        return $this->http->request('GET', self::BASE_URL . $path, $this->authHeaders($registrar));
    }

    /**
     * @param array<string, mixed> $registrar
     * @param array<string, mixed> $params
     */
    private function post(array $registrar, string $path, array $params): array
    {
        $headers = $this->authHeaders($registrar);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $this->http->request('POST', self::BASE_URL . $path, $headers, http_build_query($params));
    }

    /** @param array<string, mixed> $registrar */
    private function authHeaders(array $registrar): array
    {
        $email = (string) ($registrar['email'] ?? '');
        $apiKey = (string) ($registrar['api_key'] ?? '');
        $hourKey = gmdate('y-m-d H');

        return [
            'username' => $email,
            'token' => base64_encode(hash_hmac('sha256', $apiKey, "{$email}:{$hourKey}")),
        ];
    }

    /** @param array<int, string> $nameservers */
    private function nameserverPayload(array $nameservers): array
    {
        $keys = ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'];
        $payload = [];

        foreach ($keys as $i => $key) {
            if (isset($nameservers[$i])) {
                $payload[$key] = $nameservers[$i];
            }
        }

        return $payload;
    }

    private function enc(string $domain): string
    {
        return rawurlencode($domain);
    }

    /** @param array{status: int, body: string} $response */
    private function toResult(array $response, string $successMessage): array
    {
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /**
     * Best-effort normalization of an undocumented response shape — see
     * the class docblock. Treats HTTP 200 plus the absence of an explicit
     * error/failure indicator as success; adjust once real responses are seen.
     *
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the Upperlink API.', 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['success' => false, 'message' => "Unexpected response (HTTP {$response['status']}).", 'data' => []];
        }

        $status = strtolower((string) ($decoded['status'] ?? ($decoded['result'] ?? '')));
        // Some REST APIs signal failure via an explicit boolean `success`
        // field alongside a plain HTTP 200, rather than (or in addition to)
        // an `error`/`status: error` convention — checked separately since
        // otherwise a real `{"success": false, ...}` response would be
        // silently misread as a success (found via a hardening test, not
        // documented behavior — no live example response exists to confirm
        // Upperlink actually uses this shape).
        $explicitError = isset($decoded['error'])
            || $status === 'error' || $status === 'fail' || $status === 'failed'
            || ($decoded['success'] ?? true) === false;
        $success = $response['status'] === 200 && !$explicitError;

        $fallbackMessage = $success ? 'OK' : "Upperlink API error (HTTP {$response['status']}).";

        return [
            'success' => $success,
            'message' => (string) ($decoded['message'] ?? ($decoded['error'] ?? $fallbackMessage)),
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded,
        ];
    }
}
