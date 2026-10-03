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

        if (self::isFirewallPage((string) $response['body'])) {
            return self::cloudflare($provider, $status, (string) $response['body']);
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

    /**
     * Cloudflare's block page names the reason (error 1010, 1020, a bot check), the IP
     * it saw and a Ray ID. The provider's support needs exactly those to allow the
     * server, so they are passed on rather than summarised away.
     */
    private static function cloudflare(string $provider, int $status, string $body): string
    {
        $details = self::cloudflareDetails($body);
        $code = $details['code'];

        $reason = match (true) {
            $details['challenge'] => 'Cloudflare answered with a bot check ("Just a moment..."), which an API client cannot pass',
            $code === '1010' => 'Cloudflare blocked the request because of how the client identified itself (error 1010). The latest WHMP update sends a proper User-Agent; if this persists after updating, the block is on your server\'s IP',
            $code !== null => "Cloudflare's firewall denied access to this website's server (error {$code})",
            default => "Cloudflare's firewall blocked the request from this website's server",
        };

        $seen = ($details['ip'] !== null ? " Your server's IP as Cloudflare sees it: {$details['ip']}." : '')
            . ($details['ray'] !== null ? " Cloudflare Ray ID: {$details['ray']}." : '');

        return "{$provider} is behind Cloudflare. {$reason} (HTTP {$status}).{$seen}"
            . " To fix: open a ticket with {$provider} support asking them to allow API access from "
            . ($details['ip'] ?? "your web server's IP address")
            . ($details['ray'] !== null ? ", quoting the Ray ID" : '')
            . ". Or set PROVIDER_HTTP_PROXY in .env to send provider API calls through a server with a clean IP (for example one of your own VPSs).";
    }

    /** @return array{code: ?string, ip: ?string, ray: ?string, challenge: bool} */
    public static function cloudflareDetails(string $body): array
    {
        $code = null;
        $ip = null;
        $ray = null;

        if (preg_match('/cf-error-code[^>]*>\s*(\d{4})\s*</i', $body, $m) === 1
            || preg_match('/Error(?:\s+code)?\s*:?\s*(1\d{3})\b/i', $body, $m) === 1) {
            $code = $m[1];
        }

        if (preg_match('/cf-footer-ip[^>]*>\s*([0-9a-fA-F:.]{3,45})\s*</', $body, $m) === 1
            || preg_match('/Your IP(?:\s+address)?\s*:?(?:\s|<[^>]*>|Click to reveal)*([0-9]{1,3}(?:\.[0-9]{1,3}){3}|[0-9a-fA-F]{0,4}(?::[0-9a-fA-F]{0,4}){2,7})/i', $body, $m) === 1) {
            $candidate = trim($m[1]);
            $ip = filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : null;
        }

        if (preg_match('/Ray ID\s*:?(?:\s|<[^>]*>)*([0-9a-f]{16})/i', $body, $m) === 1) {
            $ray = strtolower($m[1]);
        }

        $lower = strtolower($body);
        $challenge = str_contains($lower, 'just a moment') || str_contains($lower, 'cf-chl') || str_contains($lower, 'challenge-platform');

        return ['code' => $code, 'ip' => $ip, 'ray' => $ray, 'challenge' => $challenge];
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
