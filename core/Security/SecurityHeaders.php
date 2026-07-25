<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Response;

/**
 * Baseline security headers applied to every response (blueprint §5
 * security hardening). script-src has no 'unsafe-inline' — every inline
 * onclick/onchange/onsubmit in the view layer was moved to
 * public/assets/js/app.js's event-delegation listeners specifically so
 * this could be locked down. style-src keeps 'unsafe-inline' for the
 * per-request theme-color <style> block (blueprint §5 theme system) —
 * CSS injection is a materially smaller attack surface than script
 * injection, so that's a deliberate, narrower trade-off than script-src.
 */
final class SecurityHeaders
{
    public static function apply(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader(
                'Content-Security-Policy',
                // merchant.payhub.com.ng is allowed as a script/frame/connect
                // source so PayHub's inline checkout can load and run its popup.
                // script-src deliberately does NOT carry 'unsafe-inline': the
                // PayHub integration lives in app.js behind a [data-payhub-pay]
                // delegated listener precisely so this stays locked down —
                // re-adding it would silently re-enable every injected inline
                // script across the app, which is what this class exists to stop.
                "default-src 'self'; script-src 'self' https://merchant.payhub.com.ng; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: https:; font-src 'self'; connect-src 'self' https://merchant.payhub.com.ng; "
                . "frame-src 'self' https://merchant.payhub.com.ng; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https:"
            );
    }
}
