<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Provisioning\HttpClient;

/**
 * Thin client for the Cloudflare v4 API, authenticated with a scoped API token
 * (`Authorization: Bearer …`). Only the calls the add-on uses; every response is
 * Cloudflare's envelope `{success, errors[], messages[], result, result_info}`.
 *
 * The HTTP layer is the injected HttpClient, so tests replay recorded responses
 * and nothing here ever needs the network.
 *
 * Free plan only: nothing in this class can change a zone's plan.
 */
final class CloudflareApi
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token
    ) {
    }

    // ------------------------------------------------------------ account / token

    /** @return array<string, mixed> {id, status, expires_on …} */
    public function verifyToken(): array
    {
        return $this->call('GET', '/user/tokens/verify');
    }

    /** @return array<int, array<string, mixed>> */
    public function accounts(): array
    {
        return $this->call('GET', '/accounts', null, ['per_page' => 50]);
    }

    // ------------------------------------------------------------ zones

    /** @return array<string, mixed> the new zone (status pending, name_servers …) */
    public function createZone(string $name, string $accountId): array
    {
        return $this->call('POST', '/zones', ['name' => $name, 'account' => ['id' => $accountId], 'type' => 'full']);
    }

    /** @return array<string, mixed> */
    public function zone(string $zoneId): array
    {
        return $this->call('GET', '/zones/' . self::id($zoneId));
    }

    /** @return array<string, mixed>|null the zone with this exact name in the account, if any */
    public function findZone(string $name, string $accountId): ?array
    {
        $rows = $this->call('GET', '/zones', null, ['name' => $name, 'account.id' => $accountId, 'per_page' => 5]);

        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $name) === 0) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function setPaused(string $zoneId, bool $paused): array
    {
        return $this->call('PATCH', '/zones/' . self::id($zoneId), ['paused' => $paused]);
    }

    public function deleteZone(string $zoneId): void
    {
        $this->call('DELETE', '/zones/' . self::id($zoneId));
    }

    /** Asks Cloudflare to re-check the nameservers now instead of on its own schedule. */
    public function activationCheck(string $zoneId): void
    {
        $this->call('PUT', '/zones/' . self::id($zoneId) . '/activation_check');
    }

    // ------------------------------------------------------------ DNS

    /** @return array<string, mixed> {recs_added, total_records_parsed} */
    public function scanDns(string $zoneId): array
    {
        return $this->call('POST', '/zones/' . self::id($zoneId) . '/dns_records/scan');
    }

    /** @return array<int, array<string, mixed>> every record (all pages, capped at 5,000) */
    public function dnsRecords(string $zoneId): array
    {
        $all = [];

        for ($page = 1; $page <= 10; $page++) {
            [$rows, $info] = $this->callWithInfo('GET', '/zones/' . self::id($zoneId) . '/dns_records', null, ['per_page' => 500, 'page' => $page]);
            $all = array_merge($all, $rows);

            if ($page >= (int) ($info['total_pages'] ?? 1)) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array<string, mixed> $record type, name, content, ttl, proxied, priority
     * @return array<string, mixed>
     */
    public function createDns(string $zoneId, array $record): array
    {
        return $this->call('POST', '/zones/' . self::id($zoneId) . '/dns_records', $record);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateDns(string $zoneId, string $recordId, array $fields): array
    {
        return $this->call('PATCH', '/zones/' . self::id($zoneId) . '/dns_records/' . self::id($recordId), $fields);
    }

    public function deleteDns(string $zoneId, string $recordId): void
    {
        $this->call('DELETE', '/zones/' . self::id($zoneId) . '/dns_records/' . self::id($recordId));
    }

    /** The zone as a BIND file (plain text, not JSON). */
    public function exportDns(string $zoneId): string
    {
        $response = $this->send('GET', '/zones/' . self::id($zoneId) . '/dns_records/export');
        $decoded = json_decode($response['body'], true);

        if ($response['status'] >= 400 || (is_array($decoded) && ($decoded['success'] ?? true) === false)) {
            throw CloudflareApiException::fromResponse($response['status'], is_array($decoded) ? $decoded : [], $response['error'] ?? null);
        }

        return $response['body'];
    }

    // ------------------------------------------------------------ settings & cache

    /** @return array<string, mixed> setting id => value */
    public function settings(string $zoneId): array
    {
        $out = [];

        foreach ($this->call('GET', '/zones/' . self::id($zoneId) . '/settings') as $row) {
            if (isset($row['id'])) {
                $out[(string) $row['id']] = $row['value'] ?? null;
            }
        }

        return $out;
    }

    public function setSetting(string $zoneId, string $setting, mixed $value): void
    {
        if (preg_match('/^[a-z0-9_]{2,40}$/', $setting) !== 1) {
            throw new CloudflareApiException('Unknown setting.');
        }

        $this->call('PATCH', '/zones/' . self::id($zoneId) . '/settings/' . $setting, ['value' => $value]);
    }

    public function purgeEverything(string $zoneId): void
    {
        $this->call('POST', '/zones/' . self::id($zoneId) . '/purge_cache', ['purge_everything' => true]);
    }

    /** @param array<int, string> $urls up to 30 full URLs */
    public function purgeUrls(string $zoneId, array $urls): void
    {
        $this->call('POST', '/zones/' . self::id($zoneId) . '/purge_cache', ['files' => array_values($urls)]);
    }

    // ------------------------------------------------------------ IP access rules

    /** @return array<int, array<string, mixed>> */
    public function accessRules(string $zoneId): array
    {
        return $this->call('GET', '/zones/' . self::id($zoneId) . '/firewall/access_rules/rules', null, ['per_page' => 100]);
    }

    /** @return array<string, mixed> */
    public function createAccessRule(string $zoneId, string $mode, string $target, string $value, string $notes): array
    {
        return $this->call('POST', '/zones/' . self::id($zoneId) . '/firewall/access_rules/rules', [
            'mode' => $mode,
            'configuration' => ['target' => $target, 'value' => $value],
            'notes' => $notes,
        ]);
    }

    public function deleteAccessRule(string $zoneId, string $ruleId): void
    {
        $this->call('DELETE', '/zones/' . self::id($zoneId) . '/firewall/access_rules/rules/' . self::id($ruleId));
    }

    // ------------------------------------------------------------ transport

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     */
    private function call(string $method, string $path, ?array $body = null, array $query = []): mixed
    {
        return $this->callWithInfo($method, $path, $body, $query)[0];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private function callWithInfo(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $response = $this->send($method, $path, $body, $query);
        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            throw CloudflareApiException::fromResponse($response['status'], is_array($decoded) ? $decoded : [], $response['error'] ?? null);
        }

        return [$decoded['result'] ?? null, is_array($decoded['result_info'] ?? null) ? $decoded['result_info'] : []];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     * @return array{status: int, body: string, error?: string}
     */
    private function send(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (trim($this->token) === '') {
            throw new CloudflareApiException('Cloudflare is not connected yet — add an API token in Addons → Cloudflare.');
        }

        $url = self::BASE . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        $payload = null;

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return $this->http->request($method, $url, $headers, $payload);
    }

    /** Cloudflare ids are 32 hex chars; anything else must never reach a URL path. */
    private static function id(string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $id) !== 1) {
            throw new CloudflareApiException('Invalid Cloudflare identifier.');
        }

        return $id;
    }
}
