<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Provisioning\HttpClient;

final class WhoisService
{
    private const WHOIS_SERVERS = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'info' => 'whois.afilias.net',
        'biz' => 'whois.biz',
        'co' => 'whois.nic.co',
        'io' => 'whois.nic.io',
        'me' => 'whois.nic.me',
        'us' => 'whois.nic.us',
        'uk' => 'whois.nic.uk',
        'co.uk' => 'whois.nic.uk',
        'org.uk' => 'whois.nic.uk',
        'me.uk' => 'whois.nic.uk',
        'ca' => 'whois.cira.ca',
        'de' => 'whois.denic.de',
        'in' => 'whois.registry.in',
        'co.in' => 'whois.registry.in',
        'au' => 'whois.auda.org.au',
        'com.au' => 'whois.auda.org.au',
        'xyz' => 'whois.nic.xyz',
        'online' => 'whois.nic.online',
        'site' => 'whois.nic.site',
        'store' => 'whois.nic.store',
        'tech' => 'whois.nic.tech',
        'club' => 'whois.nic.club',
        'top' => 'whois.nic.top',
        'vip' => 'whois.nic.vip',
        'work' => 'whois.nic.work',
        'live' => 'whois.nic.live',
        'space' => 'whois.nic.space',
        'website' => 'whois.nic.website',
        'app' => 'whois.nic.google',
        'dev' => 'whois.nic.google',
        'page' => 'whois.nic.google',
        'ng' => 'whois.nic.net.ng',
        'com.ng' => 'whois.nic.net.ng',
        'org.ng' => 'whois.nic.net.ng',
        'net.ng' => 'whois.nic.net.ng',
        'gov.ng' => 'whois.nic.net.ng',
        'edu.ng' => 'whois.nic.net.ng',
        'sch.ng' => 'whois.nic.net.ng',
        'name.ng' => 'whois.nic.net.ng',
        'mobi.ng' => 'whois.nic.net.ng',
        'i.ng' => 'whois.nic.net.ng',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Fetches raw WHOIS output for a given domain name.
     *
     * @return array{success: bool, domain: string, whois: string, server: ?string, error: ?string}
     */
    public function lookup(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !str_contains($domain, '.')) {
            return [
                'success' => false,
                'domain' => $domain,
                'whois' => '',
                'server' => null,
                'error' => 'Invalid domain name specified.',
            ];
        }

        $parts = explode('.', $domain);
        $tld = end($parts);
        if (count($parts) > 2) {
            $subTld = $parts[count($parts) - 2] . '.' . $tld;
            if (isset(self::WHOIS_SERVERS[$subTld])) {
                $tld = $subTld;
            }
        }

        $server = self::WHOIS_SERVERS[$tld] ?? 'whois.iana.org';

        // 1. Try socket port 43 WHOIS lookup
        $rawWhois = $this->querySocket($server, $domain);

        // 2. For .ng domains or fallback HTTP lookup if socket port 43 is blocked by host firewall
        if ($rawWhois === null && str_ends_with($domain, '.ng')) {
            $rawWhois = $this->queryNicNgHttp($domain);
        }

        if ($rawWhois === null || trim($rawWhois) === '') {
            return [
                'success' => false,
                'domain' => $domain,
                'whois' => 'WHOIS query returned no response from server ' . $server,
                'server' => $server,
                'error' => 'Could not connect to WHOIS server (' . $server . ').',
            ];
        }

        return [
            'success' => true,
            'domain' => $domain,
            'whois' => trim($rawWhois),
            'server' => $server,
            'error' => null,
        ];
    }

    private function querySocket(string $server, string $domain): ?string
    {
        $fp = @fsockopen($server, 43, $errno, $errstr, 6);
        if (!$fp) {
            return null;
        }

        fputs($fp, $domain . "\r\n");

        $response = '';
        while (!feof($fp)) {
            $response .= fgets($fp, 512);
        }
        fclose($fp);

        return $response !== '' ? $response : null;
    }

    private function queryNicNgHttp(string $domain): ?string
    {
        $url = 'https://whois.nic.net.ng/domain/' . urlencode($domain);
        $res = $this->http->request('GET', $url, []);

        if ($res['status'] === 200 && !empty($res['body'])) {
            $clean = strip_tags($res['body']);
            return trim($clean);
        }

        return null;
    }
}
