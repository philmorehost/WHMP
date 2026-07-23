<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\HttpClient;

/**
 * ResellerClub (LogicBoxes httpapi.com) domain reseller module, built
 * from LogicBoxes' publicly documented REST API — same "best documented
 * shape, not live-verified" caveat every registrar/gateway module in this
 * codebase carries (see UpperlinkRegistrarModule's docblock for the fuller
 * version of this disclaimer). Auth is two query params
 * (auth-userid/api-key) on every request rather than a signed header,
 * which is genuinely how this API works — not a simplification.
 *
 * One real gap versus the interface: ResellerClub's API only accepts
 * order-id (its own internal id for a purchased domain), not the domain
 * name, for most account-management calls (nameservers, lock, contacts).
 * resolveOrderId() looks it up from the domain name via
 * domains/details.json on every call rather than caching it — this app
 * has nowhere to persist the registrar's own order id per domain, and an
 * extra lookup call is a fair trade for not needing a schema change.
 */
final class ResellerClubRegistrarModule implements RegistrarModule
{
    private const BASE_URL = 'https://httpapi.com/api';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'ResellerClub',
            'description' => 'Domain registration/transfer/renewal via the ResellerClub (LogicBoxes) reseller API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'reseller_id' => ['type' => 'text', 'label' => 'Reseller ID (auth-userid)', 'default' => ''],
            'api_key' => ['type' => 'password', 'label' => 'API Key', 'default' => ''],
            'customer_id' => ['type' => 'text', 'label' => 'Default Customer ID', 'default' => ''],
        ];
    }

    public function register(array $params): array
    {
        $registrar = $params['registrar'];
        $ns = $this->nameserverPayload($params['nameservers'] ?? []);
        $contactId = (string) ($params['contacts']['contact_id'] ?? '');

        $query = array_merge([
            'domain-name' => $params['domain'],
            'years' => (string) ($params['years'] ?? 1),
            'customer-id' => (string) ($registrar['customer_id'] ?? ''),
            'reg-contact-id' => $contactId,
            'admin-contact-id' => $contactId,
            'tech-contact-id' => $contactId,
            'billing-contact-id' => $contactId,
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => ($params['id_protection'] ?? false) ? 'true' : 'false',
        ], $ns);

        $response = $this->call($registrar, 'POST', '/domains/register.json', $query);

        return $this->toResult($response, 'Domain registered.');
    }

    public function transfer(array $params): array
    {
        $registrar = $params['registrar'];
        $contactId = (string) ($params['contacts']['contact_id'] ?? '');

        $response = $this->call($registrar, 'POST', '/domains/transfer.json', [
            'domain-name' => $params['domain'],
            'auth-code' => $params['eppCode'] ?? '',
            'customer-id' => (string) ($registrar['customer_id'] ?? ''),
            'reg-contact-id' => $contactId,
            'admin-contact-id' => $contactId,
            'tech-contact-id' => $contactId,
            'billing-contact-id' => $contactId,
            'invoice-option' => 'NoInvoice',
        ]);

        return $this->toResult($response, 'Domain transfer initiated.');
    }

    public function renew(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $response = $this->call($registrar, 'POST', '/domains/renew.json', [
            'order-id' => $orderId,
            'years' => (string) ($params['years'] ?? 1),
            'exp-date' => (string) ($params['currentExpiryTimestamp'] ?? ''),
        ]);

        return $this->toResult($response, 'Domain renewed.');
    }

    public function getNameservers(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'nameservers' => []];
        }

        $response = $this->call($registrar, 'GET', '/domains/details.json', ['order-id' => $orderId, 'options' => 'NsDetails']);
        $decoded = $this->decode($response);

        $data = $decoded['data'] ?? [];
        $nameservers = [];

        if (isset($data['ns']) && is_array($data['ns'])) {
            $nameservers = array_values(array_filter(array_map('trim', $data['ns'])));
        } elseif (isset($data['nameservers']) && is_array($data['nameservers'])) {
            $nameservers = array_values(array_filter(array_map('trim', $data['nameservers'])));
        } else {
            for ($i = 1; $i <= 6; $i++) {
                $val = trim((string) ($data["ns{$i}"] ?? ($data["nameserver{$i}"] ?? '')));
                if ($val !== '') {
                    $nameservers[] = $val;
                }
            }
        }

        return ['success' => !empty($nameservers), 'nameservers' => $nameservers];
    }

    public function saveNameservers(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
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

        $query = ['order-id' => $orderId];
        foreach ($ns as $i => $val) {
            $query['ns' . ($i + 1)] = $val;
        }

        $response = $this->call($registrar, 'POST', '/domains/modify-ns.json', $query);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            $check = $this->getNameservers($params);
            if ($check['success'] && !empty($check['nameservers'])) {
                return ['success' => true, 'message' => 'Nameservers updated.', 'nameservers' => $check['nameservers']];
            }
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => 'Nameservers updated.', 'nameservers' => $ns];
    }

    public function registerChildNs(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $cname = $params['hostname'];
        if (str_contains($cname, '.')) {
            $cname = explode('.', $cname)[0];
        }

        $response = $this->call($registrar, 'POST', '/domains/child-ns/add.json', [
            'order-id' => $orderId,
            'cname' => $cname,
            'ip' => $params['ip'],
        ]);

        return $this->toResult($response, 'Private nameserver registered.');
    }

    public function deleteChildNs(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $cname = $params['hostname'];
        if (str_contains($cname, '.')) {
            $cname = explode('.', $cname)[0];
        }

        $response = $this->call($registrar, 'POST', '/domains/child-ns/delete.json', [
            'order-id' => $orderId,
            'cname' => $cname,
            'ip' => $params['ip'],
        ]);

        return $this->toResult($response, 'Private nameserver deleted.');
    }

    public function getContactInfo(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'contacts' => []];
        }

        $response = $this->call($registrar, 'GET', '/domains/details.json', ['order-id' => $orderId, 'options' => 'ContactIds']);
        $decoded = $this->decode($response);

        return ['success' => $decoded['success'], 'contacts' => $decoded['data'] ?? []];
    }

    public function saveContactInfo(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $contacts = (array) ($params['contacts'] ?? []);
        $response = $this->call($registrar, 'POST', '/domains/modify-contact.json', [
            'order-id' => $orderId,
            'reg-contact-id' => (string) ($contacts['contact_id'] ?? ''),
            'admin-contact-id' => (string) ($contacts['contact_id'] ?? ''),
            'tech-contact-id' => (string) ($contacts['contact_id'] ?? ''),
            'billing-contact-id' => (string) ($contacts['contact_id'] ?? ''),
        ]);

        return $this->toResult($response, 'Contact info updated.');
    }

    public function getRegistrarLock(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'locked' => false];
        }

        $response = $this->call($registrar, 'GET', '/domains/details.json', ['order-id' => $orderId, 'options' => 'OrderDetails']);
        $decoded = $this->decode($response);

        return ['success' => $decoded['success'], 'locked' => (string) ($decoded['data']['orderstatus'] ?? '') === 'Active' && !empty($decoded['data']['isLocked'])];
    }

    public function setRegistrarLock(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $lock = (bool) ($params['lock'] ?? true);
        $endpoint = $lock ? '/domains/enable-theft-protection.json' : '/domains/disable-theft-protection.json';
        $response = $this->call($registrar, 'POST', $endpoint, ['order-id' => $orderId]);
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
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        // 1. Direct details.json lookup which returns authcode in ResellerClub API
        $detailsResponse = $this->call($registrar, 'GET', '/domains/details.json', ['order-id' => $orderId, 'options' => 'OrderDetails']);
        $detailsDecoded = $this->decode($detailsResponse);

        if ($detailsDecoded['success']) {
            $code = $this->extractEppCode($detailsResponse['body'] ?? '', $detailsDecoded);
            if ($code !== null && $code !== '') {
                return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.'];
            }
        }

        // 2. Trigger email send as standard fallback for LogicBoxes
        $response = $this->call($registrar, 'POST', '/domains/customer-default-contact.json', ['order-id' => $orderId]);
        $decoded = $this->decode($response);

        $code = $this->extractEppCode($response['body'] ?? '', $decoded);
        if ($code !== null && $code !== '') {
            return ['success' => true, 'eppCode' => $code, 'message' => 'EPP code retrieved.'];
        }

        return [
            'success' => $decoded['success'],
            'eppCode' => '',
            'message' => $decoded['success'] ? 'EPP code emailed to the registrant contact.' : $decoded['message'],
        ];
    }

    /**
     * Deep extraction helper for EPP / Auth code across ResellerClub response shapes.
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
            if ($str !== '') {
                return $str;
            }
        }

        if (is_array($data)) {
            $keys = [
                'authcode', 'authCode', 'AuthCode', 'auth-code',
                'eppCode', 'eppcode', 'EPPCode', 'domainsecretkey',
                'DomainSecretKey', 'domainSecretKey', 'secretKey', 'code'
            ];
            foreach ($keys as $k) {
                if (!empty($data[$k]) && is_scalar($data[$k])) {
                    return trim((string) $data[$k]);
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
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $endpoint = $enabled ? '/domains/purchase-privacy.json' : '/domains/disable-privacy-protection.json';
        $response = $this->call($registrar, 'POST', $endpoint, ['order-id' => $orderId]);

        return $this->toResult($response, $enabled ? 'ID protection enabled.' : 'ID protection disabled.');
    }

    public function checkAvailability(array $params): array
    {
        $registrar = $params['registrar'];
        $domain = (string) $params['domain'];
        $dotPos = strpos($domain, '.');
        $name = $dotPos !== false ? substr($domain, 0, $dotPos) : $domain;
        $tld = $dotPos !== false ? substr($domain, $dotPos + 1) : 'com';

        $response = $this->call($registrar, 'GET', '/domains/available.json', ['domain-name' => $name, 'tlds' => $tld]);
        $decoded = $this->decode($response);

        // available.json's response is keyed by "{name}.{tld}" rather than
        // wrapped in a top-level {status,data} envelope like other
        // endpoints — normalized here rather than in decode() since this
        // is the one endpoint documented to look different.
        $raw = is_array($decoded['data']) && isset($decoded['data'][$domain]) ? $decoded['data'][$domain] : ($decoded['data'] ?? []);
        $status = strtolower((string) ($raw['status'] ?? ''));

        return [
            'success' => $decoded['success'],
            'available' => $status === 'available',
            'expiryDate' => null,
            'status' => $status !== '' ? $status : ($decoded['success'] ? 'checked' : 'error'),
        ];
    }

    public function sync(array $params): array
    {
        $registrar = $params['registrar'];
        [$orderId, $error] = $this->resolveOrderId($registrar, $params['domain']);

        if ($error !== null) {
            return ['success' => false, 'status' => null, 'expiryDate' => null];
        }

        $response = $this->call($registrar, 'GET', '/domains/details.json', ['order-id' => $orderId, 'options' => 'OrderDetails']);
        $decoded = $this->decode($response);

        $data = $decoded['data'] ?? [];
        $rawStatus = $data['orderstatus'] ?? ($data['currentstatus'] ?? ($data['status'] ?? null));

        if (is_array($rawStatus)) {
            $rawStatus = reset($rawStatus) ?: '';
            if (is_array($rawStatus)) {
                $rawStatus = (string) (current($rawStatus) ?: '');
            }
        }

        $statusStr = strtolower(trim((string) $rawStatus));
        $mappedStatus = $this->mapStatus($statusStr);

        $expiryDate = null;
        if (isset($data['endtime'])) {
            $expiryDate = gmdate('Y-m-d', (int) $data['endtime']);
        } elseif (isset($data['expirydate'])) {
            $expiryDate = date('Y-m-d', strtotime((string) $data['expirydate']));
        }

        return [
            'success' => $decoded['success'],
            'status' => $mappedStatus,
            'expiryDate' => $expiryDate,
        ];
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active' => 'active',
            'inactive', 'in_active' => 'pending',
            'deleted', 'cancelled' => 'cancelled',
            'expired' => 'expired',
            'transferredaway', 'transferred_away' => 'transferred_away',
            default => 'active',
        };
    }

    /**
     * Looks up ResellerClub's own order-id for a domain name — required
     * by nearly every account-management endpoint (see class docblock).
     *
     * @param array<string, mixed> $registrar
     * @return array{0: ?string, 1: ?string} [orderId, errorMessage]
     */
    private function resolveOrderId(array $registrar, string $domain): array
    {
        $response = $this->call($registrar, 'GET', '/domains/orderid.json', ['domain-name' => $domain]);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_scalar($decoded) || (string) $decoded === '') {
            return [null, "Could not resolve a ResellerClub order id for \"{$domain}\"."];
        }

        return [(string) $decoded, null];
    }

    /**
     * @param array<string, mixed> $registrar
     * @param array<string, mixed> $query
     */
    private function call(array $registrar, string $method, string $path, array $query): array
    {
        $query['auth-userid'] = (string) ($registrar['reseller_id'] ?? '');
        $query['api-key'] = (string) ($registrar['api_key'] ?? '');
        $url = self::BASE_URL . $path . '?' . http_build_query($query);

        return $this->http->request($method, $url, []);
    }

    /**
     * @param array<int, string> $nameservers
     * @return array<string, string>
     */
    private function nameserverPayload(array $nameservers, string $keyPrefix = 'ns'): array
    {
        $payload = [];

        foreach ($nameservers as $i => $ns) {
            $payload[$keyPrefix . ($i + 1)] = $ns;
        }

        return $payload;
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
     * ResellerClub's documented convention: a bare JSON error string/object
     * on failure (no consistent envelope across endpoints), or the actual
     * data directly on success — there's no single {status,data} wrapper
     * the way Upperlink's spec implied, so this treats "valid JSON, HTTP
     * 200, no 'status':'ERROR'/'message' key" as success.
     *
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, data: mixed}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the ResellerClub API.', 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if ($decoded === null && $response['body'] !== 'null') {
            return ['success' => false, 'message' => "Unexpected response (HTTP {$response['status']}).", 'data' => []];
        }

        $status = is_array($decoded) ? strtolower((string) ($decoded['status'] ?? '')) : '';
        $explicitError = $status === 'error' || $status === 'failed' || (is_array($decoded) && isset($decoded['message']) && $status !== 'success' && !isset($decoded['actionstatus']));
        $success = $response['status'] === 200 && !$explicitError;

        return [
            'success' => $success,
            'message' => is_array($decoded) ? (string) ($decoded['message'] ?? ($success ? 'OK' : "ResellerClub API error (HTTP {$response['status']}).")) : 'OK',
            'data' => $decoded,
        ];
    }
}
