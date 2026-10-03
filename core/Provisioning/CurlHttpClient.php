<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly bool $verifySsl = true
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

        // PHP cURL sends no User-Agent by default. Provider APIs behind Cloudflare
        // (InterServer, Nocix) can block requests without one.
        if (!$hasUserAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'WHMP-CodeVault/1.0 (PHP cURL)');
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errored = $raw === false || curl_errno($ch) !== 0;
        $errorMsg = $errored ? trim(curl_error($ch)) . ' [cURL ' . curl_errno($ch) . ']' : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $errored ? 0 : $status,
            'body' => $errored ? '' : (string) $raw,
            'error' => $errorMsg,
        ];
    }
}
