<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * A client asking to move their account to another provider on the platform.
 *
 * Reached from the client's support pages, because the request IS a support ticket:
 * submitting it opens one (handed straight to the platform, which is the only party
 * that can act on it) and records the destination on the request row, where only
 * platform staff can read it. ClientMigrationService has the rules.
 *
 * The client is always the signed-in one — there is no id in any path — so nobody can
 * ask to move somebody else's account. A not-signed-in visitor who was told "this
 * email belongs to another provider" at registration is sent here by the message on
 * that page: sign in where the account lives, then ask.
 */
final class ClientAccountMoveController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ClientMigrationService $service,
        private readonly ClientMigrationRepository $migrations
    ) {
    }

    public function form(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page([
            'requests' => $this->migrations->forRequester((int) $client['id']),
            'notice' => $this->session->pullFlash('move_notice'),
            'error' => $this->session->pullFlash('move_error'),
            'old' => $this->session->pullFlash('move_old') ?? [],
        ]);
    }

    public function submit(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $address = (string) $request->input('provider_website', '');
        $reason = (string) $request->input('reason', '');

        $result = $this->service->requestByClient($client, $address, $reason);

        if (!$result['success']) {
            $this->session->flash('move_error', (string) $result['error']);
            $this->session->flash('move_old', ['provider_website' => $address, 'reason' => $reason]);

            return Response::redirect('/client/account-move');
        }

        $ticketId = (int) ($result['ticketId'] ?? 0);

        // The ticket IS the confirmation: it shows the request in the customer's own
        // words, and the decision will arrive on it.
        if ($ticketId > 0) {
            return Response::redirect('/client/tickets/' . $ticketId);
        }

        $this->session->flash('move_notice', 'Your account move request has been sent. We will be in touch once it has been reviewed.');

        return Response::redirect('/client/account-move');
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): Response
    {
        $content = $this->view->render('support.client-account-move', $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Move your account',
            'content' => $content,
        ]));
    }
}
