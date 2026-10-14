<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * The PLATFORM's promo banners. Every read and write here is scoped to
 * `reseller_id IS NULL` (the repositories' default scope), so this page can
 * neither list nor edit a reseller's banner — resellers manage their own under
 * /client/reseller/promotions, and the two are strictly isolated.
 */
final class PromoBannerController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly PromoBannerRepository $banners,
        private readonly PromotionRepository $promotions
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'banners' => $this->banners->all(),
            'promotions' => $this->promotions->all(),
            'templates' => PromoBannerTemplates::TEMPLATES,
            'pages' => PromoBannerPages::PAGES,
            'error' => null,
            'saved' => $request->query('saved') === '1',
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        [$fields, $error] = $this->readFields($request);

        if ($error !== null) {
            return $this->render([
                'banners' => $this->banners->all(),
                'promotions' => $this->promotions->all(),
                'templates' => PromoBannerTemplates::TEMPLATES,
                'pages' => PromoBannerPages::PAGES,
                'error' => $error,
                'saved' => false,
            ]);
        }

        $fields['status'] = 'active';
        $this->banners->create($fields, null);

        return Response::redirect('/admin/promo-banners?saved=1');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];

        if ($this->banners->findScoped($id, null) === null) {
            return Response::html('404 Not Found', 404);
        }

        [$fields, $error] = $this->readFields($request);

        if ($error !== null) {
            return $this->render([
                'banners' => $this->banners->all(),
                'promotions' => $this->promotions->all(),
                'templates' => PromoBannerTemplates::TEMPLATES,
                'pages' => PromoBannerPages::PAGES,
                'error' => $error,
                'saved' => false,
            ]);
        }

        $this->banners->update($id, $fields, null);

        return Response::redirect('/admin/promo-banners?saved=1');
    }

    public function pause(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->setStatus((int) $params['id'], 'paused', null);

        return Response::redirect('/admin/promo-banners');
    }

    public function resume(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->setStatus((int) $params['id'], 'active', null);

        return Response::redirect('/admin/promo-banners');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->delete((int) $params['id'], null);

        return Response::redirect('/admin/promo-banners');
    }

    /**
     * Shared read+validate for store()/update() — a banner without a real,
     * currently-existing coupon code would advertise a code checkout rejects,
     * so the code is cross-checked against the PLATFORM's `promotions` here
     * rather than trusted as free text (see PromoBannerInput).
     *
     * @return array{0: array<string, mixed>, 1: string|null} [fields, error]
     */
    private function readFields(Request $request): array
    {
        return PromoBannerInput::read(
            $request,
            $this->promotions,
            null,
            'create it under Billing → Promotions first.'
        );
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

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('marketing.promo-banners-index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Promo Banners',
            'content' => $content,
        ]));
    }
}
