<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;

/**
 * "Remind me tomorrow" on the client-area banner that asks a client to fix an
 * email address the Email Validation scan marked invalid (Invalid Email
 * Blocker addon). Hides it for 24 hours in this session only — the banner comes
 * back on the next sign-in, because an address we can't email is worth fixing.
 */
final class InvalidEmailNoticeController
{
    public const HIDE_KEY = 'invalid_email_notice_hidden_until';

    private const HIDE_SECONDS = 86400;

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly SessionManager $session
    ) {
    }

    public function hide(Request $request): Response
    {
        if ($this->guard->currentClient() === null) {
            return Response::redirect('/client/login');
        }

        $this->session->set(self::HIDE_KEY, time() + self::HIDE_SECONDS);

        return Response::redirect(self::safeReturn((string) $request->input('return', '')));
    }

    /** Back to the page the client was on — a client-area path only, never an outside URL. */
    public static function safeReturn(string $return): string
    {
        $return = trim($return);

        return preg_match('#^/client(/[A-Za-z0-9/_\-]*)?$#', $return) === 1 ? $return : '/client';
    }
}
