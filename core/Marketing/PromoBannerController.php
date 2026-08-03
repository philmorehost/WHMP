<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class PromoBannerController
{
    private const CTA_DEFAULT = 'Apply Now';

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
        $this->banners->create($fields);

        return Response::redirect('/admin/promo-banners?saved=1');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];

        if ($this->banners->find($id) === null) {
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

        $this->banners->update($id, $fields);

        return Response::redirect('/admin/promo-banners?saved=1');
    }

    public function pause(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->setStatus((int) $params['id'], 'paused');

        return Response::redirect('/admin/promo-banners');
    }

    public function resume(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->setStatus((int) $params['id'], 'active');

        return Response::redirect('/admin/promo-banners');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->banners->delete((int) $params['id']);

        return Response::redirect('/admin/promo-banners');
    }

    /**
     * Shared read+validate for store()/update() — a banner without a real,
     * currently-existing coupon code would advertise a code checkout rejects,
     * so the code is cross-checked against `promotions` here rather than
     * trusted as free text.
     *
     * @return array{0: array<string, mixed>, 1: string|null} [fields, error]
     */
    private function readFields(Request $request): array
    {
        $name = trim((string) $request->input('name', ''));
        $template = trim((string) $request->input('template', PromoBannerTemplates::DEFAULT_KEY));
        $eyebrowText = trim((string) $request->input('eyebrow_text', ''));
        $headline = trim((string) $request->input('headline', ''));
        $subtext = trim((string) $request->input('subtext', ''));
        $couponCode = strtoupper(trim((string) $request->input('coupon_code', '')));
        $ctaText = trim((string) $request->input('cta_text', '')) ?: self::CTA_DEFAULT;
        $startsAt = trim((string) $request->input('starts_at', ''));
        $expiresAt = trim((string) $request->input('expires_at', ''));

        /** @var array<int, string> $pagesInput */
        $pagesInput = (array) $request->input('target_pages', []);
        $pages = array_values(array_intersect(
            array_merge([PromoBannerPages::ALL], array_keys(PromoBannerPages::PAGES)),
            $pagesInput
        ));

        if ($name === '' || $headline === '' || $couponCode === '') {
            return [[], 'Name, headline and coupon code are required.'];
        }

        if (!PromoBannerTemplates::isValid($template)) {
            return [[], 'Choose a valid design template.'];
        }

        if ($this->promotions->findByCode($couponCode) === null) {
            return [[], "No promotion code \"{$couponCode}\" exists — create it under Billing → Promotions first."];
        }

        if ($pages === []) {
            $pages = [PromoBannerPages::ALL];
        }

        return [[
            'name' => $name,
            'template' => $template,
            'eyebrow_text' => $eyebrowText !== '' ? $eyebrowText : null,
            'headline' => $headline,
            'subtext' => $subtext !== '' ? $subtext : null,
            'coupon_code' => $couponCode,
            'cta_text' => $ctaText,
            'target_pages' => json_encode($pages, JSON_UNESCAPED_SLASHES),
            'starts_at' => $startsAt !== '' ? $startsAt : null,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        ], null];
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
