<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class CurrencyController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CurrencyRepository $currencies
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['currencies' => $this->currencies->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $code = strtoupper(trim((string) $request->input('code', '')));
        $symbol = trim((string) $request->input('symbol', ''));
        $rate = (float) $request->input('exchange_rate', 0);

        if ($code === '' || $symbol === '' || $rate <= 0) {
            return $this->render(['currencies' => $this->currencies->all(), 'error' => 'Code, symbol, and a positive exchange rate are required.']);
        }

        if ($this->currencies->findByCode($code) !== null) {
            return $this->render(['currencies' => $this->currencies->all(), 'error' => "Currency \"{$code}\" already exists."]);
        }

        $this->currencies->create($code, $symbol, $rate);

        return Response::redirect('/admin/currencies');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $code = strtoupper(trim((string) $request->input('code', '')));
        $symbol = trim((string) $request->input('symbol', ''));
        $rate = (float) $request->input('exchange_rate', 0);

        if ($code === '' || $symbol === '' || $rate <= 0) {
            return $this->render(['currencies' => $this->currencies->all(), 'error' => 'Code, symbol, and a positive exchange rate are required.']);
        }

        $this->currencies->update($id, $code, $symbol, $rate);

        return Response::redirect('/admin/currencies');
    }

    public function setDefault(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->currencies->setDefault((int) $params['id']);

        return Response::redirect('/admin/currencies');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->currencies->delete((int) $params['id']);

        if (!$deleted) {
            return $this->render(['currencies' => $this->currencies->all(), 'error' => 'Cannot delete the default currency — set a different default first.']);
        }

        return Response::redirect('/admin/currencies');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('billing.currencies-index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Currencies',
            'content' => $content,
        ]));
    }
}
