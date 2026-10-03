<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

/**
 * Turns a failed provider API response into a sentence an admin can act on.
 *
 * "Could not reach the hosting provider API." and a blank "Connection failed."
 * used to be all Test Connection said, whatever the cause. The cause is almost
 * always one of: the server cannot make outbound HTTPS calls (firewall, DNS, old CA
 * certificates), the key was rejected, or the provider's firewall (InterServer and
 * Nocix are behind Cloudflare) blocked the request.
 */
final class ProviderHttpError
{
    /**
     * @param array{status: int, body: string, error?: string} $response
     * @param string $apiMessage the provider's own error text, when the body had one
     */
    public static function explain(array $response, string $provider, string $apiMessage = ''): string
    {
        $status = (int) $response['status'];
        $apiMessage = trim($apiMessage);

        if ($status === 0) {
            return self::transport($provider, trim((string) ($response['error'] ?? '')));
        }

        $suffix = $apiMessage !== '' ? ": {$apiMessage}" : '.';

        if ($status === 401) {
            return "{$provider} rejected the API key (HTTP 401){$suffix} Paste the key again in full (it is long, with no spaces or line breaks), or generate a new one in your {$provider} account.";
        }

        if ($status === 403 && self::isFirewallPage((string) $response['body'])) {
            return "{$provider}'s firewall (Cloudflare) blocked the request from this website's server (HTTP 403). Ask {$provider} support to allow your web server's IP address for API access.";
        }

        if ($status === 403) {
            return "{$provider} refused the request (HTTP 403){$suffix} Check that API access is enabled for this key.";
        }

        if ($apiMessage !== '') {
            return "{$provider} API error (HTTP {$status}): {$apiMessage}";
        }

        $snippet = self::snippet((string) $response['body']);

        return "{$provider} API error (HTTP {$status})" . ($snippet !== '' ? ": {$snippet}" : '.');
    }

    private static function transport(string $provider, string $error): string
    {
        $base = "Could not connect to {$provider} from this website's server";
        $lower = strtolower($error);

        $hint = match (true) {
            $error === '' => 'Check that the server can make outbound HTTPS (port 443) connections.',
            str_contains($lower, 'resolve host') => 'The server could not look up the address (DNS). Ask your host to check outbound DNS.',
            str_contains($lower, 'ssl certificate') || str_contains($lower, 'certificate') => 'The server\'s SSL CA certificates look out of date. Ask your host to update the CA bundle (ca-certificates) for PHP cURL.',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'The connection timed out. Your host\'s firewall may be blocking outbound HTTPS (port 443).',
            str_contains($lower, 'refused') || str_contains($lower, "couldn't connect") || str_contains($lower, 'failed to connect') => 'The connection was refused. Your host\'s firewall may be blocking outbound HTTPS (port 443).',
            str_contains($lower, 'ssl') || str_contains($lower, 'tls') => 'The secure (TLS) connection failed. Your host may need a newer OpenSSL/cURL, or a firewall is interfering.',
            default => 'Check that the server can make outbound HTTPS (port 443) connections.',
        };

        return $base . ($error !== '' ? " ({$error})" : '') . '. ' . $hint;
    }

    private static function isFirewallPage(string $body): bool
    {
        $lower = strtolower($body);

        return str_contains($lower, 'cloudflare')
            || str_contains($lower, 'cf-ray')
            || str_contains($lower, 'attention required')
            || str_contains($lower, 'just a moment');
    }

    private static function snippet(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return strlen($text) > 160 ? substr($text, 0, 157) . '...' : $text;
    }
}
