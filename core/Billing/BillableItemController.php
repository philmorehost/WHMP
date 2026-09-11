<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class BillableItemController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly BillableItemRepository $billableItems,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('billing.billable-items-index', [
            'items' => $this->billableItems->all(),
            'msg' => $request->query('msg'),
            'error' => $request->query('error'),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Billable Items',
            'content' => $content,
        ]));
    }

    /** Edits a still-pending item's description/amount. */
    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $item = $this->billableItems->find($id);

        if ($item === null) {
            return Response::redirect('/admin/billable-items?error=' . urlencode('Billable item not found.'));
        }

        if (($item['status'] ?? 'pending') !== 'pending') {
            return Response::redirect('/admin/billable-items?error=' . urlencode('Only pending billable items can be edited.'));
        }

        $description = trim((string) $request->input('description', ''));
        $amount = (float) $request->input('amount', 0);

        if ($description === '' || $amount < 0) {
            return Response::redirect('/admin/billable-items?error=' . urlencode('Enter a description and a non-negative amount.'));
        }

        $this->billableItems->update($id, $description, $amount);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'billable_item.updated',
            'client',
            (int) $item['client_id'],
            "Updated billable item #{$id}: \"{$description}\" (" . number_format($amount, 2) . ')',
            $request->ip()
        );

        return Response::redirect('/admin/billable-items?msg=' . urlencode('Billable item updated.'));
    }

    /** Withdraws a pending item so it is never invoiced. */
    public function cancel(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $item = $this->billableItems->find($id);

        if ($item === null) {
            return Response::redirect('/admin/billable-items?error=' . urlencode('Billable item not found.'));
        }

        $reason = trim((string) $request->input('reason', ''));

        if (!$this->billableItems->cancel($id, 'admin', $reason)) {
            return Response::redirect('/admin/billable-items?error=' . urlencode('That item is already invoiced or cancelled.'));
        }

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'billable_item.cancelled',
            'client',
            (int) $item['client_id'],
            "Cancelled billable item #{$id}" . ($reason !== '' ? ": {$reason}" : ''),
            $request->ip()
        );

        return Response::redirect('/admin/billable-items?msg=' . urlencode('Billable item cancelled.'));
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $item = $this->billableItems->find($id);

        if ($item !== null) {
            $this->billableItems->delete($id);

            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'billable_item.deleted',
                'client',
                (int) $item['client_id'],
                "Deleted billable item #{$id} (\"{$item['description']}\")",
                $request->ip()
            );
        }

        return Response::redirect('/admin/billable-items?msg=' . urlencode('Billable item deleted.'));
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::INVOICES_MANAGE)) {
            return Response::html('403 Forbidden — missing invoices.manage permission', 403);
        }

        return null;
    }
}
