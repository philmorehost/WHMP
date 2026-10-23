<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * Validates a user-supplied redirect target as a LOCAL path.
 *
 * Forms carry a `redirect` field (language switcher, currency switcher) so a
 * visitor lands back where they were. That field is attacker-controllable,
 * and two shapes of "starts with /" pass a naive check while still leaving
 * the site:
 *
 *  - `//evil.com` — a PROTOCOL-RELATIVE URL; `Location: //evil.com` sends the
 *    browser to evil.com on the current scheme. This exact bypass shipped in
 *    the language switcher while the currency switcher checked for it.
 *  - `/\evil.com` — several browsers treat the backslash as a slash in the
 *    Location header, making this scheme-relative too.
 *
 * Both are rejected here, along with control characters (which have no
 * business in a path and are the raw material of header tricks), so every
 * caller shares one hardened rule instead of a private ternary.
 */
final class LocalRedirect
{
    /** The target when the input is not a safe local path. */
    public static function sanitize(string $target, string $fallback): string
    {
        $target = trim($target);

        if ($target === '' || !str_starts_with($target, '/')) {
            return $fallback;
        }

        if (str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            return $fallback;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return $fallback;
        }

        return $target;
    }
}
