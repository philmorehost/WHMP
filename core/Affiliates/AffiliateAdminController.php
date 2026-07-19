<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class AffiliateAdminController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AffiliateRepository $affiliates,
        private readonly AffiliateCommissionRepository $commissions,
        private readonly AffiliatePayoutRequestRepository $payoutRequests,
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $affiliates = $this->affiliates->all();

        foreach ($affiliates as &$affiliate) {
            $affiliate['pending_balance'] = $this->commissions->pendingTotal((int) $affiliate['id']);
        }
        unset($affiliate);

        return $this->render('affiliates.admin-index', ['affiliates' => $affiliates]);
    }

    public function setStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->input('status', 'active');
        $this->affiliates->setStatus((int) $params['id'], $status);

        return Response::redirect('/admin/affiliates');
    }

    public function payouts(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');

        return $this->render('affiliates.admin-payouts', [
            'payoutRequests' => $this->payoutRequests->all($status !== '' ? $status : null),
            'statusFilter' => $status,
        ]);
    }

    public function approvePayout(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->affiliateService->approvePayout((int) $params['id']);

        return Response::redirect('/admin/affiliates/payouts');
    }

    public function rejectPayout(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->affiliateService->rejectPayout((int) $params['id']);

        return Response::redirect('/admin/affiliates/payouts');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::AFFILIATES_MANAGE)) {
            return Response::html('403 Forbidden — missing affiliates.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Affiliates',
            'content' => $content,
        ]));
    }
}
