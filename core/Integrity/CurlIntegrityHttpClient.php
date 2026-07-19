<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

final class CurlIntegrityHttpClient implements IntegrityHttpClient
{
    public function post(string $url, array $payload, int $timeoutSeconds): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errored = $raw === false || curl_errno($ch) !== 0;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errored) {
            return ['ok' => false, 'status' => 0, 'body' => []];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }
}
