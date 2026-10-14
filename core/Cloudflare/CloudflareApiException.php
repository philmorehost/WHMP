<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use RuntimeException;

/**
 * A Cloudflare API call failed. `getMessage()` is safe to show a client: it is
 * Cloudflare's own error text (never a token or a raw response body), translated
 * into plain words for the errors clients actually hit.
 */
final class CloudflareApiException extends RuntimeException
{
    /** @var array<int, int> Cloudflare's error codes from the response */
    public array $codes = [];

    public int $httpStatus = 0;

    /** @param array<string, mixed> $decoded */
    public static function fromResponse(int $status, array $decoded, ?string $transportError = null): self
    {
        if ($status === 0) {
            $e = new self('Could not reach Cloudflare' . ($transportError ? ' (' . $transportError . ')' : '') . '. Please try again in a moment.');
            $e->httpStatus = 0;

            return $e;
        }

        $codes = [];
        $messages = [];

        foreach ((array) ($decoded['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $codes[] = (int) ($error['code'] ?? 0);
                $messages[] = trim((string) ($error['message'] ?? ''));
            }
        }

        $e = new self(self::friendly($status, $codes, array_values(array_filter($messages))));
        $e->codes = $codes;
        $e->httpStatus = $status;

        return $e;
    }

    public function hasCode(int $code): bool
    {
        return in_array($code, $this->codes, true);
    }

    /**
     * @param array<int, int> $codes
     * @param array<int, string> $messages
     */
    private static function friendly(int $status, array $codes, array $messages): string
    {
        $raw = implode(' ', $messages);

        return match (true) {
            in_array(1061, $codes, true) => 'This domain is already set up in our Cloudflare account.',
            in_array(1097, $codes, true), in_array(1099, $codes, true), str_contains(strtolower($raw), 'not a registered domain')
                => 'Cloudflare only accepts a registered domain (like example.com), not a subdomain, and the domain must exist.',
            in_array(1105, $codes, true), str_contains(strtolower($raw), 'exceeded the limit for adding zones')
                => 'Cloudflare is temporarily not accepting new domains for our account. Please try again later — our team has been notified.',
            in_array(81057, $codes, true), in_array(81058, $codes, true) => 'An identical DNS record already exists.',
            in_array(9109, $codes, true), in_array(10000, $codes, true), $status === 401, $status === 403
                => 'Cloudflare refused the request: the API token is invalid or lacks a permission. Ask support to check the Cloudflare connection.',
            $status === 429 => 'Cloudflare is rate-limiting requests. Please wait a minute and try again.',
            $raw !== '' => 'Cloudflare: ' . mb_substr($raw, 0, 220),
            default => 'Cloudflare returned an error (HTTP ' . $status . '). Please try again.',
        };
    }
}
