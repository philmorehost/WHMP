<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * The two ends of signing in on a customer's behalf (ClientImpersonation): redeeming
 * the one-time link on the customer's site, and going back.
 */
final class ClientImpersonationController
{
    public function __construct(
        private readonly ClientImpersonation $impersonation,
        private readonly View $view
    ) {
    }

    public function start(Request $request, array $params): Response
    {
        $result = $this->impersonation->redeem((string) ($params['token'] ?? ''), $request->ip());

        if (!$result['success']) {
            return Response::html($this->view->render('layouts.client', [
                'title' => 'Link expired',
                'content' => $this->view->render('client-auth.impersonation-invalid', []),
            ]), 410)->withHeader('Referrer-Policy', 'no-referrer');
        }

        // Straight off the URL that carried the token, so it never sits in the
        // address bar, the history or a Referer header longer than this redirect.
        return Response::redirect('/client/dashboard')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function end(Request $request): Response
    {
        return Response::redirect($this->impersonation->end());
    }
}
