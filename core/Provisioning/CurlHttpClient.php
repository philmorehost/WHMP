<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly bool $verifySsl = true,
        // e.g. "http://user:pass@203.0.113.5:3128" or "socks5h://203.0.113.5:1080".
        // Lets provider calls leave from another IP when the web server's own IP is
        // blocked by the provider's Cloudflare firewall.
        private readonly ?string $proxy = null,
        // Some Cloudflare rules block a host's IPv6 range but not its IPv4 address.
        private readonly bool $forceIpv4 = false
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);

        $headerLines = [];
        $hasUserAgent = false;

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
            $hasUserAgent = $hasUserAgent || strcasecmp((string) $name, 'User-Agent') === 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            // Fail fast when the host cannot be reached at all, rather than after the
            // whole (long, for provisioning) request timeout.
            CURLOPT_CONNECTTIMEOUT => min(20, $this->timeoutSeconds),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        if ($this->proxy !== null && trim($this->proxy) !== '') {
            curl_setopt($ch, CURLOPT_PROXY, trim($this->proxy));
        }

        if ($this->forceIpv4) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        // PHP cURL sends no User-Agent by default. Provider APIs behind Cloudflare
        // (InterServer, Nocix) can block requests without one.
        if (!$hasUserAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'WHMP-CodeVault/1.0 (PHP cURL)');
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Response headers are only collected for an admin's connection test.
        $recording = HttpExchangeLog::recording();
        $responseHeaders = [];

        if ($recording) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = $line;

                return strlen($line);
            });
        }

        $raw = curl_exec($ch);
        $errored = $raw === false || curl_errno($ch) !== 0;
        $errorMsg = $errored ? trim(curl_error($ch)) . ' [cURL ' . curl_errno($ch) . ']' : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($recording) {
            $sent = $headers;

            if (!$hasUserAgent) {
                $sent['User-Agent'] = 'WHMP-CodeVault/1.0 (PHP cURL)';
            }

            HttpExchangeLog::record(
                $method,
                $url,
                $sent,
                $body,
                $errored ? 0 : $status,
                $raw === false ? '' : (string) $raw,
                $errorMsg,
                $responseHeaders,
                [
                    'primary_ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
                    'local_ip' => (string) curl_getinfo($ch, CURLINFO_LOCAL_IP),
                    'total_time' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                    'via_proxy' => $this->proxy !== null && trim($this->proxy) !== '',
                ]
            );
        }

        curl_close($ch);

        return [
            'status' => $errored ? 0 : $status,
            'body' => $errored ? '' : (string) $raw,
            'error' => $errorMsg,
        ];
    }
}
