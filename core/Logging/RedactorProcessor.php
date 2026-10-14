<?php

declare(strict_types=1);

namespace CodeVault\Logging;

use Monolog\LogRecord;

/**
 * Replaces the values of anything that looks like a credential before it is
 * written.
 *
 * A billing platform logs gateway payloads, mail headers and form submissions,
 * and those carry card tokens, API keys, session ids and passwords. Once such a
 * line is on disk it is on disk forever (log rotation notwithstanding), so the
 * redaction happens here rather than at each call site — one place to audit,
 * and impossible to forget.
 *
 * Keys are matched case-insensitively, on the key name only. Values are replaced
 * with a marker that says what happened, never truncated to something that could
 * still be a secret.
 */
final class RedactorProcessor
{
    private const SECRET_KEYS = [
        'password', 'password_hash', 'passwd', 'pass', 'secret', 'client_secret',
        'token', '_token', 'api_token', 'access_token', 'refresh_token',
        'authorization', 'auth', 'api_key', 'apikey', 'private_key',
        'card', 'card_number', 'cvv', 'cvc', 'ccv', 'pan',
        'session_id', 'cookie', 'signature', 'pin', 'otp',
        'secret_key', 'webhook_secret', 'sso_token', 'license_key',
    ];

    private const MARKER = '[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->context = $this->redactArray($record->context);
        $record->extra = $this->redactArray($record->extra);

        return $record;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSecret($key)) {
                $values[$key] = self::MARKER;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redactArray($value);
            }
        }

        return $values;
    }

    private function isSecret(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', ' ', '.'], '_', $key));

        foreach (self::SECRET_KEYS as $secret) {
            if ($normalised === $secret || str_contains($normalised, $secret)) {
                return true;
            }
        }

        return false;
    }
}
