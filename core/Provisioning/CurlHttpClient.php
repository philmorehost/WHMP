<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

final class CurlHttpClient implements HttpClient
{
    /**
     * Without the word "curl": Cloudflare firewall rules commonly block User-Agents
     * containing it, and InterServer's API sits behind Cloudflare.
     */
    public const DEFAULT_USER_AGENT = 'WHMP-CodeVault/1.0';

    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly bool $verifySsl = true,
        // e.g. "http://user:pass@203.0.113.5:3128" or "socks5h://203.0.113.5:1080".
        // Lets provider calls leave from another IP when the web server's own IP is
        // blocked by the provider's Cloudflare firewall.
        private readonly ?string $proxy = null,
        // Some Cloudflare rules block a host's IPv6 range but not its IPv4 address.
        private readonly bool $forceIpv4 = false,
        // Overrides DEFAULT_USER_AGENT (PROVIDER_HTTP_USER_AGENT), e.g. to the exact
        // value a provider's support asks API clients to send.
        private readonly ?string $userAgent = null
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
            curl_setopt($ch, CURLOPT_USERAGENT, $this->agent());
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
                $sent['User-Agent'] = $this->agent();
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

    private function agent(): string
    {
        $agent = trim((string) $this->userAgent);

        // A header value must stay on one line.
        return $agent !== '' ? (string) preg_replace('/[\r\n]+/', ' ', $agent) : self::DEFAULT_USER_AGENT;
    }
}
