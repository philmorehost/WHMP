<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class TaxRuleController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly TaxRuleRepository $taxRules,
        private readonly TaxSettings $taxSettings
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('billing.tax-rules-index', [
            'rules' => $this->taxRules->all(),
            'sellerCountryCode' => $this->taxSettings->sellerCountryCode(),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Tax Rules',
            'content' => $content,
        ]));
    }

    public function saveSellerCountry(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $country = trim((string) $request->input('seller_country_code', ''));
        $this->taxSettings->setSellerCountryCode($country !== '' ? $country : null);

        return Response::redirect('/admin/tax-rules');
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $country = trim((string) $request->input('country_code', ''));
        $state = trim((string) $request->input('state', '')) ?: null;
        $name = trim((string) $request->input('name', 'Tax'));
        $rate = (float) $request->input('rate', 0);

        if ($country !== '' && strlen($country) === 2) {
            $this->taxRules->setRate($country, $state, $name, $rate);
        }

        return Response::redirect('/admin/tax-rules');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->taxRules->delete((int) $params['id']);

        return Response::redirect('/admin/tax-rules');
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
}
