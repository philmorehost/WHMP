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
        // Records are immutable in Monolog 3, so redaction returns a copy.
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra)
        );
    }

    /**
     * The same redaction for callers that hold a plain array and not a log
     * record — the error tracker stores its context in JSON, and it must be
     * redacted by the same rules without depending on Monolog.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSecret($key)) {
                $values[$key] = self::MARKER;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * Matched by whole name or by whole word inside it, never by substring:
     * `shipping_address` has no secret in it, and neither does `author`, but
     * `authorization`, `api_key` and `card_number` do.
     */
    private function isSecret(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', ' ', '.'], '_', $key));

        if (in_array($normalised, self::SECRET_KEYS, true)) {
            return true;
        }

        foreach (explode('_', $normalised) as $word) {
            if ($word !== '' && in_array($word, self::SECRET_KEYS, true)) {
                return true;
            }
        }

        return false;
    }
}
