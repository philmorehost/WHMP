<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Client "My Orders" (blueprint §4.2 parity): a client can review their
 * pending/ongoing orders and cancel one from the portal. Cancelling an order
 * marks it cancelled and cancels the unpaid invoice the order raised — see
 * OrderCancellationService::clientCancelOrder(), reached via
 * POST /client/orders/{id}/cancel (ClientCancellationController).
 */
final class ClientOrderController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly OrderRepository $orders
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->current();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $orders = $this->orders->forClient((int) $client['id']);

        // Attach each order's items so the list can show a line count.
        foreach ($orders as &$order) {
            $order['items'] = $this->orders->items((int) $order['id']);
        }
        unset($order);

        return $this->page('billing.client-orders-index', [
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->current();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $order = $this->orders->find((int) $params['id']);

        if ($order === null || (int) $order['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $cancellable = !in_array((string) ($order['status'] ?? ''), ['cancelled', 'completed'], true);

        return $this->page('billing.client-order-show', [
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'cancellable' => $cancellable,
            'notice' => $request->query('notice') !== null ? (string) $request->query('notice') : null,
            'error' => $request->query('error') !== null ? (string) $request->query('error') : null,
        ]);
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Orders',
            'content' => $content,
        ]));
    }
}
