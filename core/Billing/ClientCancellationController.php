<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;

final class ClientCancellationController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly OrderCancellationService $orderCancellation,
        private readonly InvoiceCancellationService $invoiceCancellation
    ) {
    }

    public function cancelOrder(Request $request, array $params): Response
    {
        $client = $this->guard->current();
        if (!$client) return Response::redirect('/client/login');

        $orderId = (int)$params['id'];
        $reason = trim((string)$request->input('reason', ''));

        if ($reason === '') {
            return Response::redirect("/client/orders/{$orderId}?error=reason_required");
        }

        if ($this->orderCancellation->clientCancelOrder($orderId, (int)$client['id'], $reason)) {
            return Response::redirect("/client/orders/{$orderId}?notice=cancelled");
        }

        return Response::redirect("/client/orders/{$orderId}?error=cannot_cancel");
    }

    public function cancelInvoice(Request $request, array $params): Response
    {
        $client = $this->guard->current();
        if (!$client) return Response::redirect('/client/login');

        $invoiceId = (int)$params['id'];
        $reason = trim((string)$request->input('reason', ''));

        if ($reason === '') {
            return Response::redirect("/client/invoices/{$invoiceId}?error=reason_required");
        }

        if ($this->invoiceCancellation->clientCancelInvoice($invoiceId, (int)$client['id'], $reason)) {
            return Response::redirect("/client/invoices/{$invoiceId}?notice=cancelled");
        }

        return Response::redirect("/client/invoices/{$invoiceId}?error=cannot_cancel");
    }
}
