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
 * Phase 2 additions (addon/sub domains, redirects, email
 * forwarders/autoresponders, cron jobs, SSH keys, installed-SSL viewing,
 * disk/quota usage) call the UAPI modules cPanel documents for each
 * feature (AddonDomain, SubDomain, Redirects, Email, Cron, SSH, SSL,
 * Quota), following the same request/response shape as the phase-1
 * methods above. Like phase 1, these are NOT yet verified against a live
 * WHM server — CpanelUapiClient's defensive decoding is what protects
 * against a real server's response shape differing from cPanel's
 * documented one.
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
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'list_pops');

            if (!$result['success']) {
                return $result;
            }

            $accounts = is_array($result['data']) ? $result['data'] : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static function (array $a): array {
                    $email = (string) ($a['email'] ?? '');
                    $login = (string) ($a['login'] ?? (str_contains($email, '@') ? strstr($email, '@', true) : $email));

                    return [
                        'email' => $email !== '' ? $email : $login,
                        'login' => $login,
                        'quota' => (string) ($a['diskquota'] ?? $a['_diskquota'] ?? 'unlimited'),
                        'used' => (string) ($a['diskused'] ?? $a['_diskused'] ?? '0'),
                    ];
                }, $accounts),
            ];
        });
    }

    public function createEmailAccount(int $serviceId, string $localPart, string $password, int $quotaMb): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'add_pop',
            ['email' => $localPart, 'password' => $password, 'domain' => (string) $ctx['domain'], 'quota' => $quotaMb]
        ));
    }

    public function deleteEmailAccount(int $serviceId, string $localPart): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'delete_pop',
            ['email' => $localPart, 'domain' => (string) $ctx['domain']]
        ));
    }

    public function listFtpAccounts(int $serviceId): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call($ctx['server'], $ctx['username'], 'Ftp', 'list_ftp'));
    }

    public function createFtpAccount(int $serviceId, string $username, string $password, string $homedir, int $quotaMb): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Ftp',
            'add_ftp',
            ['user' => $username, 'pass' => $password, 'homedir' => $homedir, 'quota' => $quotaMb]
        ));
    }

    public function deleteFtpAccount(int $serviceId, string $username): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Ftp',
            'delete_ftp',
            ['user' => $username, 'destroydir' => 0]
        ));
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
            if ($ctx['domain'] === null) {
                return ['success' => false, 'message' => 'This service has no domain to look up a DNS zone for.', 'data' => []];
            }

            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'DNS', 'parse_zone', ['domain' => $ctx['domain']]);

            if (!$result['success']) {
                return $result;
            }

            $records = is_array($result['data']) ? $result['data'] : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map([$this, 'normalizeDnsRecord'], $records),
            ];
        });
    }

    /** Only the record types add_zone_record needs distinct field names for below are offered. */
    public function supportedDnsRecordTypes(): array
    {
        return array_keys(self::DNS_RECORD_FIELDS);
    }

    public function addDnsRecord(int $serviceId, string $name, string $type, string $value, int $ttl): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($name, $type, $value, $ttl) {
            if ($ctx['domain'] === null) {
                return ['success' => false, 'message' => 'This service has no domain to add a DNS record to.', 'data' => []];
            }

            $field = self::DNS_RECORD_FIELDS[$type] ?? null;

            if ($field === null) {
                return ['success' => false, 'message' => "Unsupported record type \"{$type}\".", 'data' => []];
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'DNS', 'add_zone_record', [
                'domain' => $ctx['domain'],
                'name' => $name,
                'type' => $type,
                'ttl' => $ttl,
                'class' => 'IN',
                $field => $value,
            ]);
        });
    }

    public function deleteDnsRecord(int $serviceId, int $line): array
    {
        return $this->withContext($serviceId, function (array $ctx) use ($line) {
            if ($ctx['domain'] === null) {
                return ['success' => false, 'message' => 'This service has no domain.', 'data' => []];
            }

            return $this->uapi->call($ctx['server'], $ctx['username'], 'DNS', 'remove_zone_record', [
                'domain' => $ctx['domain'],
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
        $url = is_array($result['data']) ? ($result['data']['url'] ?? null) : null;

        if (!$result['success'] || $url === null) {
            return ['success' => false, 'message' => $result['message'] ?? 'SSO request failed.'];
        }

        return ['success' => true, 'url' => (string) $url, 'message' => 'SSO link generated.'];
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

            return ['success' => true, 'message' => $result['message'], 'data' => array_values($rows)];
        });
    }

    public function createAddonDomain(int $serviceId, string $newDomain, string $subdomain, string $dir): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'AddonDomain',
            'addaddondomain',
            ['newdomain' => $newDomain, 'subdomain' => $subdomain, 'dir' => $dir]
        ));
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
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'SubDomain', 'listsubdomains');

            if (!$result['success']) {
                return $result;
            }

            return ['success' => true, 'message' => $result['message'], 'data' => is_array($result['data']) ? array_values($result['data']) : []];
        });
    }

    public function createSubdomain(int $serviceId, string $subdomain, string $rootDomain, string $dir): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'SubDomain',
            'addsubdomain',
            ['domain' => $subdomain, 'rootdomain' => $rootDomain, 'dir' => $dir]
        ));
    }

    public function deleteSubdomain(int $serviceId, string $fullDomain): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'SubDomain',
            'delsubdomain',
            ['domain' => $fullDomain, 'keepdir' => 1]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listRedirects(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Redirects', 'list_redirects');

            if (!$result['success']) {
                return $result;
            }

            return ['success' => true, 'message' => $result['message'], 'data' => is_array($result['data']) ? array_values($result['data']) : []];
        });
    }

    public function createRedirect(int $serviceId, string $domain, string $path, string $destination, string $type): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Redirects',
            'create_redirect',
            [
                'domain' => $domain,
                'subdomain' => ltrim($path, '/'),
                'dest' => $destination,
                'type' => $type === 'temporary' ? 'temporary' : 'permanent',
                'wildcard' => 0,
                'redirect_www' => 'both',
            ]
        ));
    }

    public function deleteRedirect(int $serviceId, string $sourceDomain): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Redirects',
            'delete_redirect',
            ['domain' => $sourceDomain]
        ));
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
                'data' => array_map(static fn (array $r): array => [
                    'source' => (string) ($r['address'] ?? $r['forward'] ?? ''),
                    'destination' => (string) ($r['dest'] ?? $r['fwdemail'] ?? ''),
                ], $rows),
            ];
        });
    }

    public function createForwarder(int $serviceId, string $localPart, string $destination): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'add_forwarder',
            ['domain' => (string) $ctx['domain'], 'email' => $localPart, 'fwdopt' => 'fwd', 'fwdemail' => $destination]
        ));
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
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Email', 'list_autoresponders', ['domain' => (string) $ctx['domain']]);

            if (!$result['success']) {
                return $result;
            }

            return ['success' => true, 'message' => $result['message'], 'data' => is_array($result['data']) ? array_values($result['data']) : []];
        });
    }

    public function createAutoresponder(int $serviceId, string $localPart, string $subject, string $body): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'add_autoresponder',
            [
                'email' => $localPart,
                'domain' => (string) $ctx['domain'],
                'from' => (string) $ctx['domain'],
                'subject' => $subject,
                'body' => $body,
                'is_html' => 0,
                'start' => 0,
                'stop' => 0,
            ]
        ));
    }

    public function deleteAutoresponder(int $serviceId, string $localPart): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Email',
            'delete_autoresponder',
            ['email' => $localPart, 'domain' => (string) $ctx['domain']]
        ));
    }

    /** @return array{success: bool, message: string, data: mixed} */
    public function listCronJobs(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'Cron', 'list_cronjobs');

            if (!$result['success']) {
                return $result;
            }

            return ['success' => true, 'message' => $result['message'], 'data' => is_array($result['data']) ? array_values($result['data']) : []];
        });
    }

    public function createCronJob(int $serviceId, string $minute, string $hour, string $day, string $month, string $weekday, string $command): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'Cron',
            'add_line',
            ['minute' => $minute, 'hour' => $hour, 'day' => $day, 'month' => $month, 'weekday' => $weekday, 'command' => $command]
        ));
    }

    public function deleteCronJob(int $serviceId, int $line): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
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
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'SSH', 'listkeys', ['type' => 'local']);

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? $result['data'] : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static fn (array $k): array => [
                    'name' => (string) ($k['name'] ?? ''),
                    'bits' => (string) ($k['bits'] ?? ''),
                    'authorized' => (bool) ($k['authorized'] ?? false),
                ], $rows),
            ];
        });
    }

    public function generateSshKey(int $serviceId, string $name, string $password, int $keySize): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'SSH',
            'genkey',
            ['name' => $name, 'keytype' => 'RSA', 'bits' => $keySize, 'password' => $password, 'passwordconfirm' => $password]
        ));
    }

    public function deleteSshKey(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'SSH',
            'delkey',
            ['name' => $name]
        ));
    }

    public function authorizeSshKey(int $serviceId, string $name): array
    {
        return $this->withContext($serviceId, fn (array $ctx) => $this->uapi->call(
            $ctx['server'],
            $ctx['username'],
            'SSH',
            'authorizekey',
            ['name' => $name]
        ));
    }

    /** Read-only: lists installed SSL certificates on the account's domains. */
    public function listSslCertificates(int $serviceId): array
    {
        return $this->withContext($serviceId, function (array $ctx) {
            $result = $this->uapi->call($ctx['server'], $ctx['username'], 'SSL', 'installed');

            if (!$result['success']) {
                return $result;
            }

            $rows = is_array($result['data']) ? $result['data'] : [];

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => array_map(static fn (array $c): array => [
                    'domain' => (string) ($c['host'] ?? $c['domain'] ?? ''),
                    'issuer' => (string) ($c['issuer'] ?? ''),
                    'expires' => (string) ($c['not_after'] ?? $c['expires'] ?? ''),
                ], $rows),
            ];
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

            return [
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'usedMb' => (float) ($data['megabytes_used'] ?? 0),
                    'limitMb' => (float) ($data['megabytes_limit'] ?? 0),
                ],
            ];
        });
    }

    /**
     * cPanel's parse_zone response names each record's value field
     * differently per type ("address" for A/AAAA, "cname" for CNAME,
     * "txtdata" for TXT, ...) — this collapses that into one
     * display-friendly shape so callers don't need to know cPanel's
     * per-type field names.
     *
     * @param array<string, mixed> $record
     * @return array{line: int, name: string, type: string, ttl: int, value: string}
     */
    private function normalizeDnsRecord(array $record): array
    {
        $value = $record['address']
            ?? $record['cname']
            ?? $record['txtdata']
            ?? $record['ptrdname']
            ?? $record['exchange']
            ?? (is_array($record['data'] ?? null) ? implode(' ', $record['data']) : ($record['data'] ?? ''));

        return [
            'line' => (int) ($record['line'] ?? 0),
            'name' => (string) ($record['name'] ?? ''),
            'type' => (string) ($record['record_type'] ?? $record['type'] ?? ''),
            'ttl' => (int) ($record['ttl'] ?? 0),
            'value' => (string) $value,
        ];
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

        if ($service['server_id'] === null || $service['username'] === null) {
            return [null, 'Service has not been provisioned yet.'];
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
            'username' => (string) $service['username'],
            'domain' => $service['domain'] ?? null,
        ], null];
    }
}
