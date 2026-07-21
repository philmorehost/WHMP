<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Client-facing management of saved payment methods used for automated
 * recurring billing. Methods are captured automatically the first time a
 * client pays an invoice with a gateway that returns a reusable token
 * (see PaymentCallbackController); here the client can see them, choose
 * which is charged by default, and remove one.
 */
final class PaymentMethodController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly PaymentMethodRepository $methods
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('billing.payment-methods', [
            'methods' => $this->methods->forClient((int) $client['id']),
            'status' => $request->query('status'),
        ]);
    }

    public function setDefault(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $method = $this->methods->find((int) $params['id']);

        if ($method === null || (int) $method['client_id'] !== (int) $client['id']) {
            return Response::redirect('/client/payment-methods?status=notfound');
        }

        $this->methods->makeDefault((int) $client['id'], (int) $params['id']);

        return Response::redirect('/client/payment-methods?status=default-set');
    }

    public function delete(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $method = $this->methods->find((int) $params['id']);

        if ($method === null || (int) $method['client_id'] !== (int) $client['id']) {
            return Response::redirect('/client/payment-methods?status=notfound');
        }

        $this->methods->deactivate((int) $client['id'], (int) $params['id']);

        return Response::redirect('/client/payment-methods?status=removed');
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Payment Methods',
            'content' => $content,
        ]));
    }
}
