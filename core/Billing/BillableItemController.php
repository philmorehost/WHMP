<?php

declare(strict_types=1);

namespace CodeVault\Billing;

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
        private readonly BillableItemRepository $billableItems
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::INVOICES_MANAGE)) {
            return Response::html('403 Forbidden — missing invoices.manage permission', 403);
        }

        $content = $this->view->render('billing.billable-items-index', ['items' => $this->billableItems->all()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Billable Items',
            'content' => $content,
        ]));
    }
}
