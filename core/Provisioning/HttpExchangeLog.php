<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

/**
 * Records the raw HTTP exchanges made while it is switched on, so an admin's
 * "Test Connection" can show exactly what the provider answered: status, headers,
 * body, the IP connected to, and the cURL error when nothing came back.
 *
 * Off by default and only switched on for the length of one admin test, so normal
 * traffic is never buffered. Secrets are redacted before anything leaves here.
 */
final class HttpExchangeLog
{
    private const MAX_BODY = 20000;

    private const SECRET_HEADERS = ['x-api-key', 'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'sessionid', 'x-auth-token'];

    private static bool $recording = false;

    /** @var array<int, array<string, mixed>> */
    private static array $entries = [];

    public static function start(): void
    {
        self::$recording = true;
        self::$entries = [];
    }

    public static function recording(): bool
    {
        return self::$recording;
    }

    /**
     * Stop and return what was recorded, with every value in $secrets (the server's
     * API token, passwords) replaced wherever it appears.
     *
     * @param array<int, string|null> $secrets
     * @return array<int, array<string, mixed>>
     */
    public static function stop(array $secrets = []): array
    {
        self::$recording = false;
        $entries = self::$entries;
        self::$entries = [];

        $secrets = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $secrets),
            static fn (string $v): bool => strlen($v) >= 4
        ));

        return array_map(static fn (array $entry): array => self::scrub($entry, $secrets), $entries);
    }

    /**
     * @param array<string, string> $requestHeaders
     * @param array<int, string> $responseHeaders raw header lines, status lines included
     * @param array<string, mixed> $info
     */
    public static function record(
        string $method,
        string $url,
        array $requestHeaders,
        ?string $requestBody,
        int $status,
        string $body,
        string $error,
        array $responseHeaders,
        array $info
    ): void {
        if (!self::$recording) {
            return;
        }

        $request = [];

        foreach ($requestHeaders as $name => $value) {
            $request[(string) $name] = self::isSecretHeader((string) $name) ? self::mask((string) $value) : (string) $value;
        }

        $response = [];

        foreach ($responseHeaders as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, ':') && !str_starts_with(strtoupper($line), 'HTTP/')) {
                [$name, $value] = array_map('trim', explode(':', $line, 2));
                $line = $name . ': ' . (self::isSecretHeader($name) ? self::mask($value) : $value);
            }

            $response[] = $line;
        }

        self::$entries[] = [
            'method' => strtoupper($method),
            'url' => self::stripUrlCredentials($url),
            'requestHeaders' => $request,
            'requestBodyBytes' => $requestBody === null ? 0 : strlen($requestBody),
            'status' => $status,
            'error' => $error,
            'responseHeaders' => $response,
            'body' => strlen($body) > self::MAX_BODY ? substr($body, 0, self::MAX_BODY) . "\n... [cut: " . strlen($body) . ' bytes in total]' : $body,
            'bodyBytes' => strlen($body),
            'connectedTo' => (string) ($info['primary_ip'] ?? ''),
            'localIp' => (string) ($info['local_ip'] ?? ''),
            'seconds' => round((float) ($info['total_time'] ?? 0), 3),
            'viaProxy' => (bool) ($info['via_proxy'] ?? false),
        ];
    }

    private static function isSecretHeader(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::SECRET_HEADERS, true);
    }

    /** Enough to recognise which key was sent, never enough to reuse it. */
    public static function mask(string $value): string
    {
        $value = trim($value);

        if (stripos($value, 'basic ') === 0 || stripos($value, 'bearer ') === 0) {
            [$scheme] = explode(' ', $value, 2);

            return $scheme . ' [hidden]';
        }

        $length = strlen($value);

        return $length <= 8 ? "[hidden, {$length} characters]" : substr($value, 0, 4) . '…' . substr($value, -2) . " [{$length} characters]";
    }

    private static function stripUrlCredentials(string $url): string
    {
        return (string) preg_replace('#^(\w+://)[^/@\s]+@#', '$1[hidden]@', $url);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $secrets
     * @return array<string, mixed>
     */
    private static function scrub(array $entry, array $secrets): array
    {
        if ($secrets === []) {
            return $entry;
        }

        array_walk_recursive($entry, static function (mixed &$value) use ($secrets): void {
            if (is_string($value)) {
                $value = str_replace($secrets, '[hidden]', $value);
            }
        });

        return $entry;
    }
}
