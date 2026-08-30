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

        $this->service->clientRequestsCancellation(
            $id,
            (int) $client['id'],
            $type,
            $reason,
            $type === 'due_date' && $cancelDate !== '' ? $cancelDate : null
        );

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("Cancellation request submitted successfully."));
    }

    public function adminIndex(Request $request): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/login');

        $status = (string) $request->query('status', 'pending');

        if (!in_array($status, ['pending', 'approved', 'rejected', 'completed'], true)) {
            $status = 'pending';
        }

        $requests = $this->cancellations->findByStatus($status);
        $notice = (string) $request->query('notice', '');

        $content = $this->view->render('pages.admin-cancellations', [
            'requests' => $requests,
            'status' => $status,
            'counts' => $this->cancellations->counts(),
            'notice' => $notice,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Cancellations',
            'content' => $content,
        ]));
    }

    public function adminApprove(Request $request, array $params): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/login');

        $id = (int) $params['id'];
        $notes = (string) $request->input('notes', '');

        $result = $this->service->approveCancellation($id, (int) $admin['id'], $notes);

        $status = $result['status'] ?? 'approved';
        $message = urlencode($result['message'] ?? 'Cancellation approved.');

        return Response::redirect("/admin/cancellations?status={$status}&notice={$message}");
    }

    /** Explicitly mark an approved request completed (service already cancelled). */
    public function adminComplete(Request $request, array $params): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/login');

        $result = $this->service->markCompleted((int) $params['id']);

        return Response::redirect(
            '/admin/cancellations?status=' . ($result['status'] ?? 'completed') . '&notice=' . urlencode($result['message'] ?? 'Marked completed.')
        );
    }

    public function adminReject(Request $request, array $params): Response
    {
        $admin = $this->adminGuard->current();
        if (!$admin) return Response::redirect('/login');

        $id = (int) $params['id'];
        $notes = trim((string) $request->input('notes', ''));

        if ($notes === '') {
            return Response::json(['error' => 'Rejection reason required'], 400);
        }

        $this->service->rejectCancellation($id, (int) $admin['id'], $notes);

        return Response::redirect('/admin/cancellations?status=rejected&notice=' . urlencode('Cancellation request rejected.'));
    }
}
