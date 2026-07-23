<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class PromotionController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly PromotionRepository $promotions
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('billing.promotions-index', [
            'promotions' => $this->promotions->all(),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Promotions',
            'content' => $content,
        ]));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $code = trim((string) $request->input('code', ''));
        $type = (string) $request->input('type', 'percentage');
        $maxRedemptions = trim((string) $request->input('max_redemptions', ''));
        $startsAt = trim((string) $request->input('starts_at', ''));
        $expiresAt = trim((string) $request->input('expires_at', ''));

        if ($code !== '' && in_array($type, ['percentage', 'fixed'], true)) {
            $this->promotions->save([
                'code' => $code,
                'type' => $type,
                'value' => (float) $request->input('value', 0),
                'max_redemptions' => $maxRedemptions === '' ? null : (int) $maxRedemptions,
                'min_order_amount' => (float) $request->input('min_order_amount', 0),
                'starts_at' => $startsAt === '' ? null : $startsAt,
                'expires_at' => $expiresAt === '' ? null : $expiresAt,
                'status' => (string) $request->input('status', 'active'),
            ]);
        }

        return Response::redirect('/admin/promotions');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->promotions->delete((int) $params['id']);

        return Response::redirect('/admin/promotions');
    }

    public function destroyMultiple(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = $request->input('ids');

        if (is_array($ids)) {
            foreach ($ids as $id) {
                $this->promotions->delete((int) $id);
            }
        }

        return Response::redirect('/admin/promotions');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PROMOTIONS_MANAGE)) {
            return Response::html('403 Forbidden — missing promotions.manage permission', 403);
        }

        return null;
    }
}
