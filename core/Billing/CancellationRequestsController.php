<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

final class CancellationRequestsController
{
    public function __construct(
        private readonly ClientAuthGuard $clientGuard,
        private readonly AuthGuard $adminGuard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly CancellationRequestRepository $cancellations,
        private readonly CancellationRequestService $service
    ) {
    }

    public function clientCreate(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $client = $this->clientGuard->currentClient();
        if (!$client) return Response::redirect('/client/login');

        $service = $this->services->find($id);
        if (!$service || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('Service not found', 404);
        }

        $type = (string) $request->input('type', 'end_of_period');
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            $reason = 'Cancellation requested from client portal.';
        }
        $cancelDate = (string) $request->input('cancel_date', '');

        $this->service->requestCancellation(
            $id,
            (int) $client['id'],
            $type,
            $reason,
            $type === 'due_date' ? $cancelDate : null
        );

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("Cancellation request submitted successfully."));
    }

    public function adminIndex(Request $request): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/admin/login');

        $status = (string) $request->query('status', 'pending');
        $requests = $this->cancellations->findByStatus($status);

        return Response::html($this->view->render('pages.admin-cancellations', [
            'requests' => $requests,
            'status' => $status
        ]));
    }

    public function adminApprove(Request $request, array $params): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/admin/login');

        $id = (int) $params['id'];
        $notes = (string) $request->input('notes', '');

        $this->service->approveCancellation($id, (int) $admin['id'], $notes);

        return Response::redirect('/admin/cancellations?notice=approved');
    }

    public function adminReject(Request $request, array $params): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/admin/login');

        $id = (int) $params['id'];
        $notes = trim((string) $request->input('notes', ''));

        if ($notes === '') {
            return Response::json(['error' => 'Rejection reason required'], 400);
        }

        $this->service->rejectCancellation($id, (int) $admin['id'], $notes);

        return Response::redirect('/admin/cancellations?notice=rejected');
    }
}
