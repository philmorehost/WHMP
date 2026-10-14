<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * A reseller asking the platform to move a customer: into their store, or one of their
 * own customers out to another provider. Each request opens a ticket on the
 * platform's desk (the reseller is our client) and waits for a super admin.
 *
 * NO STORE ID FROM THE REQUEST, like every other page in the reseller area: the store
 * is the signed-in client's own (ResellerStoreRepository::forClient).
 */
final class ClientResellerMigrationController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly ClientMigrationService $service,
        private readonly ClientMigrationRepository $migrations
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        return $this->page([
            'store' => $store,
            'requests' => $this->migrations->forRequester((int) $client['id']),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
            'old' => $this->session->pullFlash('reseller_move_old') ?? [],
        ]);
    }

    public function submit(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        $direction = (string) $request->input('direction', '');
        $email = (string) $request->input('client_email', '');
        $target = (string) $request->input('provider_website', '');
        $reason = (string) $request->input('reason', '');

        $result = $this->service->requestByReseller($client, $store, $direction, $email, $target, $reason);

        if ($result['success']) {
            $this->session->flash('reseller_notice', 'Request sent. Our team will verify it with the account holder and reply on your support ticket.');
        } else {
            $this->session->flash('reseller_error', (string) $result['error']);
            $this->session->flash('reseller_move_old', [
                'direction' => $direction,
                'client_email' => $email,
                'provider_website' => $target,
                'reason' => $reason,
            ]);
        }

        return Response::redirect('/client/reseller/migrations');
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): Response
    {
        $content = $this->view->render('reseller.client-migrations', $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Account moves',
            'content' => $content,
        ]));
    }
}
