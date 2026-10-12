<?php

declare(strict_types=1);

namespace CodeVault\CpanelTools;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Provisioning\ServerRepository;

/**
 * Client-area self-service equivalent of WHMCS's "cPanel Extended" addon
 * (email/FTP/database accounts, DNS zone editing, quick logins into
 * cPanel/webmail/phpMyAdmin/File Manager/Softaculous) for services
 * provisioned on a `cpanel` server. Every method resolves the service to
 * its server + cPanel username first — a service that isn't provisioned
 * yet, or isn't on a cPanel server, fails closed with a plain-English
 * reason rather than a stack trace.
 *
 * Domain, email, FTP, database, SSL, quota, and cPanel SSO operations use the
 * documented UAPI/WHM API endpoints. Cron jobs, SSH keys, DNS zone editing, and
 * subdomain management use cPanel API 2 where cPanel has no equivalent UAPI
 * operation. Calls are isolated by the provisioned service's WHM server and
 * account name.
 */
final class CpanelToolsService
{
    private const APPS = [
        'cpanel' => ['service' => 'cpaneld', 'app' => null],
        'webmail' => ['service' => 'webmaild', 'app' => null],
        'phpmyadmin' => ['service' => 'cpaneld', 'app' => 'phpmyadmin'],
        'filemanager' => ['service' => 'cpaneld', 'app' => 'filemanager'],
        // Softaculous's registered cPanel "app key" isn't perfectly
        // standardized across installs — this is the modern default, but a
        // server with a different Softaculous setup may need a different
        // key. Falls back to the plain cPanel home SSO if it doesn't work.
        'softaculous' => ['service' => 'cpaneld', 'app' => 'softaculous_instant'],
    ];

    private const DNS_RECORD_FIELDS = [
        'A' => 'address',
        'AAAA' => 'address',
        'CNAME' => 'cname',
        'TXT' => 'txtdata',
    ];

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ServerRepository $servers,
        private readonly CpanelUapiClient $uapi
    ) {
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listEmailAccounts(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'list_pops_with_disk');

            if (!$result['success']) {
                return $result;
            }

            $accounts = is_array($result['data']) ? $result['data'] : [];

            if (isset($accounts['accounts']) && is_array($accounts['accounts'])) {
                $accounts = $accounts['accounts'];
            }

            $accounts = array_values(array_filter($accounts, 'is_array'));

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static function (array $account) use ($ctx): array {
                    $email = trim((string) ($account['email'] ?? ''));
                    $login = trim((string) ($account['login'] ?? $account['user'] ?? ''));
                    $defaultDomain = trim((string) ($ctx['domain'] ?? ''));
                    $address = str_contains($email, '@')
                        ? $email
                        : (str_contains($login, '@')
                            ? $login
                            : ($login !== '' && $defaultDomain !== '' ? $login . '@' . $defaultDomain : ($login ?: $email)));
                    $quota = $account['diskquota'] ?? $account['_diskquota'] ?? $account['txtdiskquota'] ?? 'unlimited';
                    $used = $account['diskused'] ?? $account['_diskused'] ?? $account['txtdiskused'] ?? '0';
                    $primary = strcasecmp($email, 'Main Account') === 0 || !empty($account['is_primary']);

                    return [
                        'email' => $email !== '' ? $email : $address,
                        'login' => $address,
                        'quota' => is_numeric($quota) && (float) $quota <= 0 ? 'unlimited' : (string) $quota,
                        'used' => (string) $used,
                        'deletable' => !$primary,
                    ];
                }, $accounts),
            ];
        });
    }

    public function createEmailAccount(int $serviceId, string $localPart, string $password, int $quotaMb): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($localPart, $password, $quotaMb) {
            $params = $this->emailAddressParams($localPart, $ctx['domain']);
            $params['password'] = $password;
            $params['quota'] = $quotaMb;

            return $this->uapi->callPost($ctx['server'], $ctx['username'], 'Email', 'add_pop', $params);
        });
    }

    public function deleteEmailAccount(int $serviceId, string $localPart): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($localPart) {
            $params = $this->emailAddressParams($localPart, $ctx['domain']);
            $domain = (string) ($params['domain'] ?? '');

            if (
                strcasecmp((string) $params['email'], $ctx['username']) === 0
                && ($domain === '' || strcasecmp($domain, (string) $ctx['domain']) === 0)
            ) {
                return ['success' => false, 'message' => 'The primary cPanel email account cannot be deleted here.', 'data' => []];
            }

            return $this->uapi->call(
                $ctx['server'],
                $ctx['username'],
                'Email',
                'delete_pop',
                $params
            );
        });
    }

    public function listFtpAccounts(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Ftp', 'list_ftp');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['accounts'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static function (array $account) use ($ctx): array {
                    $user = trim((string) ($account['user'] ?? ''));
                    $localUser = str_contains($user, '@') ? strstr($user, '@', true) : $user;

                    return [
                        'user' => $user,
                        'homedir' => (string) ($account['homedir'] ?? ''),
                        'deletable' => $user !== ''
                            && strtolower((string) ($account['type'] ?? '')) !== 'main'
                            && strcasecmp((string) $localUser, $ctx['username']) !== 0,
                    ];
                }, $rows),
            ];
        });
    }

    public function createFtpAccount(int $serviceId, string $username, string $password, string $homedir, int $quotaMb): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($username, $password, $homedir, $quotaMb) {
            $username = trim($username);
            $domain = trim((string) ($ctx['domain'] ?? ''));
            $at = strrpos($username, '@');

            if ($at !== false) {
                $domain = trim(substr($username, $at + 1));
                $username = trim(substr($username, 0, $at));
            }

            $params = ['user' => $username, 'pass' => $password, 'homedir' => $homedir, 'quota' => $quotaMb];

            if ($domain !== '') {
                $params['domain'] = $domain;
            }

            return $this->uapi->callPost($ctx['server'], $ctx['username'], 'Ftp', 'add_ftp', $params);
        });
    }

    public function deleteFtpAccount(int $serviceId, string $username): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($username) {
            $username = trim($username);
            $domain = trim((string) ($ctx['domain'] ?? ''));
            $at = strrpos($username, '@');

            if ($at !== false) {
                $domain = trim(substr($username, $at + 1));
                $username = trim(substr($username, 0, $at));
            }

            if ($username === '' || strcasecmp($username, $ctx['username']) === 0) {
                return ['success' => false, 'message' => 'The primary cPanel FTP account cannot be deleted here.', 'data' => []];
            }

            $params = ['user' => $username, 'destroy' => 0];

            if ($domain !== '') {
                $params['domain'] = $domain;
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'Ftp', 'delete_ftp', $params);
        });
    }

    public function listDatabases(int $serviceId): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call($ctx['server'], $ctx['username'], 'Mysql', 'list_databases'));
    }

    public function createDatabase(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Mysql',
            'create_database',
            ['name' => $name]
        ));
    }

    public function deleteDatabase(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Mysql',
            'delete_database',
            ['name' => $name]
        ));
    }

    public function listDnsRecords(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            if (trim((string) ($ctx['domain'] ?? '')) === '') {
                return ['success' => false, 'message' => 'This service has no domain to look up a DNS zone for.', 'data' => []];
            }

            $result = $this->loadDnsZone($ctx);

            if (!$result['success']) {
                return $result;
            }

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map([$this, 'normalizeDnsRecord'], $result['data']),
            ];
        });
    }

    /** Only record types whose data layout is supported by the editor are offered. */
    public function supportedDnsRecordTypes(): array
    {
        return array_keys(self::DNS_RECORD_FIELDS);
    }

    public function addDnsRecord(int $serviceId, string $name, string $type, string $value, int $ttl): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($name, $type, $value, $ttl) {
            $domain = trim((string) ($ctx['domain'] ?? ''));

            if ($domain === '') {
                return ['success' => false, 'message' => 'This service has no domain to add a DNS record to.', 'data' => []];
            }

            $type = strtoupper(trim($type));
            $value = trim($value);

            if (!isset(self::DNS_RECORD_FIELDS[$type])) {
                return ['success' => false, 'message' => "Unsupported record type \"{$type}\".", 'data' => []];
            }

            if ($value === '' || $ttl < 60) {
                return ['success' => false, 'message' => 'Enter a record value and a TTL of at least 60 seconds.', 'data' => []];
            }

            $normalizedName = $this->normalizeDnsName($name, $domain);

            if ($normalizedName === null) {
                return ['success' => false, 'message' => 'The DNS name must be the service domain or a name within that domain.', 'data' => []];
            }

            $params = [
                'domain' => $domain,
                'name' => $normalizedName,
                'type' => $type,
                self::DNS_RECORD_FIELDS[$type] => $value,
                'ttl' => $ttl,
                'class' => 'IN',
            ];

            if ($type === 'CNAME' && strcasecmp(rtrim($normalizedName, '.'), rtrim($domain, '.')) === 0) {
                $params['flatten'] = 1;
            }

            // cPanel has no UAPI function for adding zone records; use its
            // documented ZoneEdit API 2 function rather than a non-existent
            // DNS::mass_edit_zone endpoint.
            return $this->uapi->callApi2($ctx['server'], $ctx['username'], 'ZoneEdit', 'add_zone_record', $params);
        });
    }

    public function deleteDnsRecord(int $serviceId, int $line, string $name, string $type, string $value): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($line, $name, $type, $value) {
            $domain = trim((string) ($ctx['domain'] ?? ''));

            if ($domain === '' || $line < 1) {
                return ['success' => false, 'message' => 'This DNS record cannot be deleted because its zone or line number is unavailable.', 'data' => []];
            }

            $zone = $this->loadDnsZone($ctx);

            if (!$zone['success']) {
                return $zone;
            }

            $current = null;

            foreach ($zone['data'] as $record) {
                if (!is_array($record) || (int) ($record['line_index'] ?? $record['line'] ?? $record['Line'] ?? -1) !== $line) {
                    continue;
                }

                $current = $this->normalizeDnsRecord($record);
                break;
            }

            if ($current === null
                || $current['name'] !== $name
                || strcasecmp($current['type'], $type) !== 0
                || $current['value'] !== $value) {
                return ['success' => false, 'message' => 'This DNS record changed since the page loaded. Refresh the zone and try again.', 'data' => []];
            }

            $currentType = strtoupper($current['type']);

            if ($currentType === 'SOA') {
                return ['success' => false, 'message' => 'The SOA record cannot be deleted from the DNS Zone Editor.', 'data' => []];
            }

            if ($currentType === '' || str_starts_with($currentType, ':') || str_starts_with($currentType, '$')) {
                return ['success' => false, 'message' => 'This DNS zone metadata line cannot be deleted from the editor.', 'data' => []];
            }

            return $this->uapi->callApi2($ctx['server'], $ctx['username'], 'ZoneEdit', 'remove_zone_record', [
                'domain' => $domain,
                'line' => $line,
            ]);
        });
    }

    /** @return array{success: bool, url?: string, message: string} */
    public function ssoUrl(int $serviceId, string $app): array
    {
        [$ctx, $error] = $this->context($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $target = self::APPS[$app] ?? null;

        if ($target === null) {
            return ['success' => false, 'message' => "Unknown quick-login target \"{$app}\"."];
        }

        $params = ['user' => $ctx['username'], 'service' => $target['service']];

        if ($target['app'] !== null) {
            $params['app'] = $target['app'];
        }

        $result = $this->uapi->callWhm($ctx['server'], 'create_user_session', $params);
        $url = is_array($result['data']) ? trim((string) ($result['data']['url'] ?? '')) : '';

        if ((!$result['success'] || $url === '') && $app === 'softaculous') {
            $fallback = $this->uapi->callWhm($ctx['server'], 'create_user_session', [
                'user' => $ctx['username'],
                'service' => 'cpaneld',
            ]);
            $fallbackUrl = is_array($fallback['data']) ? trim((string) ($fallback['data']['url'] ?? '')) : '';

            if ($fallback['success'] && $fallbackUrl !== '') {
                return ['success' => true, 'url' => $fallbackUrl, 'message' => 'Softaculous SSO is unavailable; opened cPanel home instead.'];
            }

            return [
                'success' => false,
                'message' => 'Softaculous SSO failed (' . ($result['message'] ?? 'no SSO URL returned')
                    . '); the cPanel home fallback failed (' . ($fallback['message'] ?? 'no SSO URL returned') . ').',
            ];
        }

        if (!$result['success'] || $url === '') {
            return ['success' => false, 'message' => $result['message'] ?? 'SSO request failed.'];
        }

        return ['success' => true, 'url' => $url, 'message' => 'SSO link generated.'];
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listAddonDomains(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'AddonDomain', 'listaddondomains');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['addondomains'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return ['success' => true, 'message' => $result['message'], 'data' => $rows];
        });
    }

    public function createAddonDomain(int $serviceId, string $newDomain, string $subdomain, string $dir): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($newDomain, $subdomain, $dir) {
            $params = ['newdomain' => trim($newDomain), 'subdomain' => trim($subdomain)];

            if (trim($dir) !== '') {
                $params['dir'] = trim($dir);
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'AddonDomain', 'addaddondomain', $params);
        });
    }

    public function deleteAddonDomain(int $serviceId, string $domain, string $subdomain): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'AddonDomain',
            'deladdondomain',
            ['domain' => $domain, 'subdomain' => $subdomain]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listSubdomains(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            // No equivalent UAPI function exists for this detailed listing.
            $result = $this->uapi->callApi2($ctx['server'], $ctx['username'], 'SubDomain', 'listsubdomains');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['subdomains'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return ['success' => true, 'message' => $result['message'], 'data' => $rows];
        });
    }

    public function createSubdomain(int $serviceId, string $subdomain, string $rootDomain, string $dir): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($subdomain, $rootDomain, $dir) {
            $rootDomain = trim($rootDomain) ?: trim((string) ($ctx['domain'] ?? ''));
            $params = ['domain' => trim($subdomain), 'rootdomain' => $rootDomain];

            if (trim($dir) !== '') {
                $params['dir'] = trim($dir);
            }

            // cPanel documents no UAPI equivalent for creating subdomains.
            return $this->uapi->callApi2($ctx['server'], $ctx['username'], 'SubDomain', 'addsubdomain', $params);
        });
    }

    public function deleteSubdomain(int $serviceId, string $fullDomain): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->callApi2(
            $ctx['server'],
            $ctx['username'],
            'SubDomain',
            'delsubdomain',
            ['domain' => trim($fullDomain)]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listRedirects(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Mime', 'list_redirects');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['redirects'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static fn (array $redirect): array => [
                    'domain' => (string) ($redirect['domain'] ?? $redirect['urldomain'] ?? $redirect['displaydomain'] ?? ''),
                    'source' => (string) ($redirect['source'] ?? $redirect['sourceurl'] ?? $redirect['displaysourceurl'] ?? ''),
                    'dest' => (string) ($redirect['targeturl'] ?? $redirect['destination'] ?? $redirect['dest'] ?? ''),
                    'type' => (string) ($redirect['type'] ?? $redirect['statuscode'] ?? ''),
                ], $rows),
            ];
        });
    }

    public function createRedirect(int $serviceId, string $path, string $destination, string $type): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($path, $destination, $type) {
            $domain = trim((string) ($ctx['domain'] ?? ''));

            if ($domain === '') {
                return ['success' => false, 'message' => 'This service has no domain for a redirect.', 'data' => []];
            }

            $params = [
                'domain' => $domain,
                'redirect' => trim($destination),
                'type' => strtolower(trim($type)) === 'temp' ? 'temp' : 'permanent',
                'redirect_wildcard' => 0,
                'redirect_www' => 0,
            ];
            $path = trim($path);

            if ($path !== '' && $path !== '/') {
                $params['src'] = '/' . ltrim($path, '/');
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'Mime', 'add_redirect', $params);
        });
    }

    public function deleteRedirect(int $serviceId, string $domain, string $source): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($domain, $source) {
            $domain = trim($domain);
            $source = trim($source);

            if ($domain === '' || $source === '') {
                return ['success' => false, 'message' => 'The redirect domain or source path is missing. Refresh the page and try again.', 'data' => []];
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'Mime', 'delete_redirect', [
                'domain' => $domain,
                'src' => $source,
            ]);
        });
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listForwarders(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'list_forwarders', ['domain' => (string) $ctx['domain']]);

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? $result['data'] : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static fn (array $row): array => [
                    'source' => (string) ($row['dest'] ?? $row['address'] ?? ''),
                    'destination' => (string) ($row['forward'] ?? $row['fwdemail'] ?? ''),
                ], array_values(array_filter($rows, 'is_array'))),
            ];
        });
    }

    public function createForwarder(int $serviceId, string $localPart, string $destination): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($localPart, $destination) {
            $address = $this->emailAddressParams($localPart, $ctx['domain']);
            $domain = trim((string) ($address['domain'] ?? ''));

            if ($domain === '') {
                return ['success' => false, 'message' => 'This service has no email domain for a forwarder.', 'data' => []];
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'add_forwarder', [
                'domain' => $domain,
                'email' => $address['email'] . '@' . $domain,
                'fwdopt' => 'fwd',
                'fwdemail' => $destination,
            ]);
        });
    }

    public function deleteForwarder(int $serviceId, string $address, string $forwarder): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'delete_forwarder',
            ['address' => $address, 'forwarder' => $forwarder]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listAutoresponders(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'list_auto_responders', ['domain' => (string) $ctx['domain']]);

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['autoresponders'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return ['success' => true, 'message' => $result['message'], 'data' => $rows];
        });
    }

    public function createAutoresponder(int $serviceId, string $localPart, string $subject, string $body, int $stop): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($localPart, $subject, $body, $stop) {
            $domain = trim((string) ($ctx['domain'] ?? ''));

            if ($domain === '' || $stop <= time()) {
                return ['success' => false, 'message' => 'Choose an autoresponder end date and time in the future.', 'data' => []];
            }

            $params = $this->emailAddressParams($localPart, $domain);
            $params['from'] = $domain;
            $params['interval'] = 24;
            $params['subject'] = $subject;
            $params['body'] = $body;
            $params['is_html'] = 0;
            $params['start'] = time();
            $params['stop'] = $stop;

            return $this->uapi->callPost($ctx['server'], $ctx['username'], 'Email', 'add_auto_responder', $params);
        });
    }

    public function deleteAutoresponder(int $serviceId, string $email): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($email) {
            $address = $this->emailAddressParams($email, $ctx['domain']);
            $domain = trim((string) ($address['domain'] ?? ''));
            $fullAddress = $address['email'] . ($domain !== '' ? '@' . $domain : '');

            return $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'delete_auto_responder', [
                'email' => $fullAddress,
            ]);
        });
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listCronJobs(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->callApi2($ctx['server'], $ctx['username'], 'Cron', 'listcron');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? $result['data'] : [];
            $rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row) && trim((string) ($row['command'] ?? $row['command_htmlsafe'] ?? '')) !== ''));

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static fn (array $job): array => [
                    'line' => (int) ($job['count'] ?? $job['line'] ?? 0),
                    'minute' => (string) ($job['minute'] ?? ''),
                    'hour' => (string) ($job['hour'] ?? ''),
                    'day' => (string) ($job['day'] ?? ''),
                    'month' => (string) ($job['month'] ?? ''),
                    'weekday' => (string) ($job['weekday'] ?? ''),
                    'command' => (string) ($job['command'] ?? $job['command_htmlsafe'] ?? ''),
                ], $rows),
            ];
        });
    }

    public function createCronJob(int $serviceId, string $minute, string $hour, string $day, string $month, string $weekday, string $command): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->callApi2Post(
            $ctx['server'],
            $ctx['username'],
            'Cron',
            'add_line',
            ['minute' => $minute, 'hour' => $hour, 'day' => $day, 'month' => $month, 'weekday' => $weekday, 'command' => $command]
        ));
    }

    public function deleteCronJob(int $serviceId, int $line): array
    {
        if ($line < 1) {
            return ['success' => false, 'message' => 'The cron job line is invalid.', 'data' => []];
        }

        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->callApi2(
            $ctx['server'],
            $ctx['username'],
            'Cron',
            'remove_line',
            ['line' => $line]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listSshKeys(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->callApi2($ctx['server'], $ctx['username'], 'SSH', 'listkeys');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? $result['data'] : [];
            $rows = array_values(array_filter($rows, 'is_array'));

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static function (array $key): array {
                    $authStatus = strtolower(trim((string) ($key['authstatus'] ?? $key['status'] ?? $key['authorized'] ?? $key['auth'] ?? '')));

                    return [
                        'name' => (string) ($key['name'] ?? ''),
                        'authorized' => in_array($authStatus, ['1', 'true', 'yes', 'authorized'], true),
                    ];
                }, $rows),
            ];
        });
    }

    public function generateSshKey(int $serviceId, string $name, string $password, int $keySize): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($name, $password, $keySize) {
            $params = ['name' => $name, 'bits' => $keySize, 'type' => 'rsa'];

            if ($password !== '') {
                $params['pass'] = $password;
            }

            return $this->uapi->callApi2Post($ctx['server'], $ctx['username'], 'SSH', 'genkey', $params);
        });
    }

    public function deleteSshKey(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->callApi2(
            $ctx['server'],
            $ctx['username'],
            'SSH',
            'delkey',
            ['name' => $name]
        ));
    }

    public function authorizeSshKey(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->callApi2(
            $ctx['server'],
            $ctx['username'],
            'SSH',
            'authkey',
            ['key' => $name, 'action' => 'authorize']
        ));
    }

    /** Read-only: lists installed SSL certificates on the account's domains. */
    public function listSslCertificates(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'SSL', 'list_certs');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? ($result['data']['certificates'] ?? $result['data']['installed_hosts'] ?? $result['data']['hosts'] ?? $result['data']) : [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(fn (array $certificate): array => [
                    'domain' => $this->sslDomains($certificate),
                    'issuer' => $this->sslIssuer($certificate),
                    'expires' => $this->sslExpiration($certificate),
                    'installed' => !array_key_exists('domain_is_configured', $certificate) || (string) $certificate['domain_is_configured'] === '1',
                ], $rows),
            ];
        });
    }

    /** Whether this is a provisioned account on a cPanel server. */
    public function isCpanelService(int $serviceId): bool
    {
        return $this->context($serviceId)[1] === null;
    }

    /** Installs an SSL certificate and matching private key with cPanel UAPI. */
    public function installSslCertificate(int $serviceId, string $domain, string $certificate, string $privateKey, string $caBundle = ''): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($domain, $certificate, $privateKey, $caBundle) {
            $domain = trim($domain);
            $certificate = trim($certificate);
            $privateKey = trim($privateKey);

            if ($domain === '' || $certificate === '' || $privateKey === '') {
                return ['success' => false, 'message' => 'Enter the domain, certificate, and matching private key.', 'data' => []];
            }

            $params = ['domain' => $domain, 'cert' => $certificate, 'key' => $privateKey];
            $caBundle = trim($caBundle);

            if ($caBundle !== '') {
                $params['cabundle'] = $caBundle;
            }

            // Certificate and private-key material must stay out of the URL and
            // access logs, so this operation deliberately uses the POST client.
            return $this->uapi->callPost($ctx['server'], $ctx['username'], 'SSL', 'install_ssl', $params);
        });
    }

    /** Read-only: disk/quota usage for the account. */
    public function diskUsage(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Quota', 'get_quota_info');

            if (!$result['success']) {
                return $result;
            }

            $data = is_array($result['data']) ? $result['data'] : [];
            $used = $data['megabytes_used'] ?? null;
            $limit = $data['megabyte_limit'] ?? null;

            if (!is_numeric($used) || !is_numeric($limit)) {
                return ['success' => false, 'message' => 'cPanel returned quota data in an unexpected format.', 'data' => []];
            }

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'usedMb' => max(0.0, (float) $used),
                    'limitMb' => max(0.0, (float) $limit),
                ],
            ];
        });
    }

    /** @return array{success: bool, message: string, data: array<int, array<string, mixed>>} */
    private function loadDnsZone(array $ctx): array
    {
        $domain = trim((string) ($ctx['domain'] ?? ''));
        $result = $this->uapi->callApi2($ctx['server'], $ctx['username'], 'ZoneEdit', 'fetchzone', ['domain' => $domain]);

        if (!$result['success']) {
            return $result;
        }

        $data = is_array($result['data']) ? $result['data'] : [];
        $zone = $data;

        // API 2 returns one envelope row containing `record`, `statusmsg`, and
        // the SOA serial. Some cPanel releases return the row directly.
        if (self::isListArray($data) && count($data) === 1 && is_array($data[0]) && (isset($data[0]['record']) || isset($data[0]['statusmsg']))) {
            $zone = $data[0];
        }

        $status = $zone['status'] ?? null;

        if ($status !== null && (string) $status !== '1') {
            $message = trim((string) ($zone['statusmsg'] ?? $result['message'] ?? ''));
            return ['success' => false, 'message' => $message !== '' ? $message : 'cPanel could not read this DNS zone.', 'data' => []];
        }

        $records = $zone['record'] ?? $zone['records'] ?? $zone['zone'] ?? $data;

        if (!is_array($records)) {
            $records = [];
        } elseif (!self::isListArray($records)) {
            $records = isset($records['record_type']) || isset($records['type']) ? [$records] : [];
        }

        return [
            'success' => true,
            'message' => (string) ($zone['statusmsg'] ?? $result['message'] ?? 'OK'),
            'data' => array_values(array_filter($records, 'is_array')),
        ];
    }

    /** @param array<string, mixed> $record */
    private function normalizeDnsRecord(array $record): array
    {
        $rawValue = $record['address']
            ?? $record['cname']
            ?? $record['txtdata']
            ?? $record['ptrdname']
            ?? $record['exchange']
            ?? $record['nsdname']
            ?? $record['raw']
            ?? $record['record']
            ?? $record['data']
            ?? '';

        if (is_array($rawValue)) {
            $parts = [];

            foreach ($rawValue as $part) {
                if (is_scalar($part)) {
                    $parts[] = (string) $part;
                }
            }

            $value = implode(' ', $parts);
        } else {
            $value = is_scalar($rawValue) ? (string) $rawValue : '';
        }

        $line = $record['line_index'] ?? $record['line'] ?? $record['Line'] ?? -1;

        return [
            'line' => is_numeric($line) ? (int) $line : -1,
            'name' => (string) ($record['dname'] ?? $record['name'] ?? ''),
            'type' => strtoupper((string) ($record['record_type'] ?? $record['type'] ?? '')),
            'ttl' => (int) ($record['ttl'] ?? 0),
            'value' => $value,
        ];
    }

    private function normalizeDnsName(string $name, string $domain): ?string
    {
        $name = trim($name);
        $domain = trim($domain);

        if ($name === '' || $domain === '' || str_starts_with($name, '.') || str_ends_with($name, '..') || str_ends_with($domain, '..')) {
            return null;
        }

        // A terminal dot makes a DNS name absolute; remove just that one dot,
        // but never silently trim a leading or repeated edge dot into a valid
        // relative label.
        $absoluteName = str_ends_with($name, '.');

        if ($absoluteName) {
            $name = substr($name, 0, -1);
        }

        if (str_ends_with($domain, '.')) {
            $domain = substr($domain, 0, -1);
        }

        if ($name === '' || $domain === '' || str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            return null;
        }

        $domainLower = strtolower($domain);
        $nameLower = strtolower($name);
        $zoneSuffix = '.' . $domainLower;

        // An absolute name must identify this zone (or its apex); a bare
        // "www." is not the relative label "www" but an outside FQDN.
        if ($absoluteName && $nameLower !== $domainLower && !str_ends_with($nameLower, $zoneSuffix)) {
            return null;
        }

        // ZoneEdit API 2 expects the owner label relative to the zone (for
        // example, "www" in the example.test zone), not the fully-qualified
        // name returned by fetchzone. Keep the apex as the zone's own name.
        if ($nameLower === '@' || $nameLower === $domainLower) {
            return $domain;
        }

        if (str_ends_with($nameLower, $zoneSuffix)) {
            $name = substr($name, 0, -strlen($zoneSuffix));
        } elseif (str_contains($name, '.')) {
            // A dotted name not ending in this zone would otherwise be
            // interpreted as a relative label and silently land elsewhere.
            return null;
        }

        if ($name === ''
            || str_starts_with($name, '.')
            || str_ends_with($name, '.')
            || str_contains($name, '..')
            || preg_match('/\s/', $name) === 1
            || str_contains($name, '/')
            || str_contains($name, chr(92))
            || strlen($name) > 253) {
            return null;
        }

        return $name;
    }

    /**
     * Convert either a mailbox local part or a full address into the exact
     * local-part/domain pair expected by cPanel's Email UAPI functions.
     *
     * @return array{email: string, domain?: string}
     */
    private function emailAddressParams(string $email, ?string $defaultDomain): array
    {
        $email = trim($email);
        $domain = trim((string) ($defaultDomain ?? ''));
        $at = strrpos($email, '@');

        if ($at !== false) {
            $domain = trim(substr($email, $at + 1));
            $email = trim(substr($email, 0, $at));
        }

        $params = ['email' => $email];

        if ($domain !== '') {
            $params['domain'] = $domain;
        }

        return $params;
    }

    /** @param array<string, mixed> $certificate */
    private function sslDomains(array $certificate): string
    {
        $metadata = is_array($certificate['certificate'] ?? null) ? $certificate['certificate'] : [];
        $domains = $certificate['fqdns'] ?? $certificate['domains'] ?? $metadata['fqdns'] ?? $metadata['domains'] ?? [];

        if (is_string($domains)) {
            $domains = [$domains];
        }

        if (is_array($domains)) {
            $domains = array_values(array_filter(array_map(
                static fn ($domain): string => is_scalar($domain) ? trim((string) $domain) : '',
                $domains
            ), static fn (string $domain): bool => $domain !== ''));
        } else {
            $domains = [];
        }

        if ($domains === []) {
            foreach (['servername', 'host', 'domain'] as $key) {
                if (!empty($certificate[$key]) && is_scalar($certificate[$key])) {
                    $domains[] = trim((string) $certificate[$key]);
                    break;
                }
            }
        }

        return implode(', ', $domains);
    }

    /** @param array<string, mixed> $certificate */
    private function sslIssuer(array $certificate): string
    {
        $metadata = is_array($certificate['certificate'] ?? null) ? $certificate['certificate'] : [];
        $issuer = $metadata['issuer']
            ?? (isset($certificate['issuer.organizationName']) ? ['organizationName' => $certificate['issuer.organizationName']] : null)
            ?? $certificate['issuer']
            ?? $certificate['issuer_text']
            ?? '';

        if (is_scalar($issuer)) {
            return trim((string) $issuer);
        }

        if (!is_array($issuer)) {
            return '';
        }

        $parts = [];

        foreach (['organizationName', 'organization', 'O', 'commonName', 'CN', 'countryName', 'C'] as $key) {
            if (isset($issuer[$key]) && is_scalar($issuer[$key])) {
                $parts[] = trim((string) $issuer[$key]);
            }
        }

        if ($parts === []) {
            foreach ($issuer as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $parts[] = trim((string) $value);
                }
            }
        }

        return implode(', ', array_unique($parts));
    }

    /** @param array<string, mixed> $certificate */
    private function sslExpiration(array $certificate): string
    {
        $metadata = is_array($certificate['certificate'] ?? null) ? $certificate['certificate'] : [];
        $expires = $metadata['not_after'] ?? $metadata['expires'] ?? $certificate['not_after'] ?? $certificate['expires'] ?? '';

        if (!is_scalar($expires) || trim((string) $expires) === '') {
            return '';
        }

        $expires = trim((string) $expires);

        if (ctype_digit($expires)) {
            if (strlen($expires) === 12) {
                $date = \DateTimeImmutable::createFromFormat('!YmdHi', $expires);
                return $date !== false ? $date->format('Y-m-d') : $expires;
            }

            if (strlen($expires) === 14) {
                $date = \DateTimeImmutable::createFromFormat('!YmdHis', $expires);
                return $date !== false ? $date->format('Y-m-d') : $expires;
            }

            $timestamp = (int) $expires;

            if (strlen($expires) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            if ($timestamp > 0 && $timestamp < 100000000000) {
                return (new \DateTimeImmutable('@' . $timestamp))->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($expires))->format('Y-m-d');
        } catch (\Throwable) {
            return $expires;
        }
    }

    private static function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param callable(array{server: array<string, mixed>, username: string, domain: ?string}): array $fn
     */
    private function withContext(int $serviceId, callable $fn): array
    {
        [$ctx, $error] = $this->context($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error, 'data' => []];
        }

        return $fn($ctx);
    }

    /**
     * @return array{0: ?array{server: array<string, mixed>, username: string, domain: ?string}, 1: ?string}
     */
    private function context(int $serviceId): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return [null, 'Service not found.'];
        }

        if ($service['server_id'] === null) {
            return [null, 'Service has not been assigned to a cPanel server.'];
        }

        $username = trim((string) ($service['username'] ?? ''));

        if ($username === '') {
            return [null, 'This service is missing its cPanel username. An administrator must verify the account username and assigned server.'];
        }

        $server = $this->servers->find((int) $service['server_id']);

        if ($server === null) {
            return [null, 'Assigned server no longer exists.'];
        }

        if ((string) ($server['module_slug'] ?? '') !== 'cpanel') {
            return [null, 'This service is not hosted on a cPanel server.'];
        }

        return [[
            'server' => $server,
            'username' => $username,
            'domain' => $service['domain'] ?? null,
        ], null];
    }
}
