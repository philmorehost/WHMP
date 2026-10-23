<?php

declare(strict_types=1);

namespace CodeVault\Api;

/**
 * Strips credential material from rows leaving through the REST API.
 *
 * WHY THIS EXISTS
 *
 * The API's read endpoints return whole repository rows, and several of those
 * rows carry authentication secrets as a matter of course:
 *
 *  - a clients row (SELECT c.*) includes `password_hash`,
 *    `security_pin_hash`, `two_factor_secret` and `two_factor_recovery_codes`;
 *  - a services row (SELECT s.*) includes the hosting account `password`.
 *
 * A staff-scoped key (`clients.read`) is trusted to READ client data — it is
 * NOT the credential store. Handing out password hashes and TOTP secrets means
 * one leaked API key converts directly into account takeover for every client
 * on the install: the recovery codes alone defeat 2FA, and the hash is
 * offline-crackable at the attacker's leisure. These fields are stripped here,
 * at the single boundary every /api response passes through, rather than in
 * the repositories — admin pages and services legitimately consume full rows;
 * it is only the MACHINE-FACING API that has no reason to ever carry them.
 *
 * The lists are deny-by-field, not allow-by-field, deliberately: an allow
 * list would silently drop every future column from API responses (a stale
 * docs page and a "broken" integration), while a deny list degrades safely —
 * a new sensitive column must be added here once, loudly, at review time.
 */
final class ApiSanitizer
{
    /** Client-row columns that are credentials, not data. */
    private const CLIENT_SECRET_FIELDS = [
        'password_hash',
        'security_pin_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** Service-row columns that are credentials, not data. */
    private const SERVICE_SECRET_FIELDS = [
        'password',
    ];

    public static function client(array $row): array
    {
        return self::strip($row, self::CLIENT_SECRET_FIELDS);
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function clientRows(array $rows): array
    {
        return array_map(self::client(...), $rows);
    }

    public static function service(array $row): array
    {
        return self::strip($row, self::SERVICE_SECRET_FIELDS);
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function serviceRows(array $rows): array
    {
        return array_map(self::service(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function strip(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            unset($row[$field]);
        }

        return $row;
    }
}
