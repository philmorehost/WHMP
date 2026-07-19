<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class DomainPricingController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DomainPricingRepository $pricing,
        private readonly RegistrarRepository $registrars
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('domains.pricing-index', [
            'pricingList' => $this->pricing->all(),
            'registrars' => $this->registrars->allEnabled(),
            'error' => null,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Domain Pricing',
            'content' => $content,
        ]));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $tld = trim((string) $request->input('tld', ''));
        $registrarSlug = trim((string) $request->input('registrar_slug', ''));
        $registerPrice = (float) $request->input('register_price', 0.0);
        $transferPrice = (float) $request->input('transfer_price', 0.0);
        $renewPrice = (float) $request->input('renew_price', 0.0);

        if ($tld === '' || $registrarSlug === '') {
            $content = $this->view->render('domains.pricing-index', [
                'pricingList' => $this->pricing->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => 'TLD and Registrar are required fields.',
            ]);

            return Response::html($this->view->render('layouts.admin', [
                'title' => 'CodeVault Admin — Domain Pricing',
                'content' => $content,
            ]));
        }

        $this->pricing->save([
            'tld' => $tld,
            'registrar_slug' => $registrarSlug,
            'register_price' => $registerPrice,
            'transfer_price' => $transferPrice,
            'renew_price' => $renewPrice,
        ]);

        return Response::redirect('/admin/domain-pricing');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->pricing->delete((int) $params['id']);

        return Response::redirect('/admin/domain-pricing');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::DOMAINS_MANAGE)) {
            return Response::html('403 Forbidden — missing domains.manage permission', 403);
        }

        return null;
    }
}
