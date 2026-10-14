<?php

declare(strict_types=1);

namespace CodeVault\Theme;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ThemeController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ThemeSettings $theme,
        private readonly ?\CodeVault\Settings\SettingsRepository $settings = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['theme' => $this->theme->get(), 'error' => null, 'saved' => false]);
    }

    /**
     * Admin → Theme → Website design: the main website's look (premium or
     * classic) and the words on its home page. Stores are unaffected — they
     * always use their own storefront design and their own headline.
     */
    public function updateWebsite(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        if ($this->settings === null) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Settings are not available.', 'saved' => false]);
        }

        $design = (string) $request->input('site_design', PlatformSite::DESIGN_PREMIUM) === PlatformSite::DESIGN_CLASSIC
            ? PlatformSite::DESIGN_CLASSIC
            : PlatformSite::DESIGN_PREMIUM;
        $clip = static fn (string $value, int $max): string => mb_substr(trim(strip_tags($value)), 0, $max);

        $this->settings->set(PlatformSite::DESIGN_KEY, $design);
        $this->settings->set(PlatformSite::HEADLINE_KEY, $clip((string) $request->input('home_headline', ''), 120));
        $this->settings->set(PlatformSite::TAGLINE_KEY, $clip((string) $request->input('home_tagline', ''), 320));
        $this->settings->set(PlatformSite::TRUST_KEY, $clip((string) $request->input('trust_line', ''), 160));

        return $this->render(['theme' => $this->theme->get(), 'error' => null, 'saved' => true, 'websiteSaved' => true]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $brandName = trim((string) $request->input('brand_name', ''));
        $logoUrl = trim((string) $request->input('logo_url', ''));
        $faviconUrl = trim((string) $request->input('favicon_url', ''));
        $primaryColor = trim((string) $request->input('primary_color', ''));
        $termsUrl = trim((string) $request->input('terms_url', ''));

        if (!$this->theme->isValidHex($primaryColor)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Primary color must be a hex code like #2f6fed.', 'saved' => false]);
        }

        if ($termsUrl !== '' && !filter_var($termsUrl, FILTER_VALIDATE_URL)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Terms of Service URL must be a full URL like https://yourdomain.com/terms.', 'saved' => false]);
        }

        // Handle uploaded favicon file if provided
        $file = $request->file('favicon_file');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed = ['ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

            if (!in_array($ext, $allowed, true)) {
                return $this->render(['theme' => $this->theme->get(), 'error' => 'Invalid favicon file type. Allowed formats: .ico, .png, .jpg, .svg, .webp', 'saved' => false]);
            }

            $uploadsDir = dirname(__DIR__, 2) . '/public/uploads';
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }

            $filename = 'favicon_' . time() . '.' . $ext;
            $destination = $uploadsDir . '/' . $filename;

            if (move_uploaded_file((string) $file['tmp_name'], $destination)) {
                $faviconUrl = '/uploads/' . $filename;
            }
        }

        $this->theme->save(
            $brandName,
            $logoUrl !== '' ? $logoUrl : null,
            $primaryColor,
            $termsUrl !== '' ? $termsUrl : null,
            $faviconUrl !== '' ? $faviconUrl : null
        );

        return $this->render(['theme' => $this->theme->get(), 'error' => null, 'saved' => true]);
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
        $data['website'] = [
            'design' => PlatformSite::premiumFor($this->settings?->get(PlatformSite::DESIGN_KEY, PlatformSite::DESIGN_PREMIUM))
                ? PlatformSite::DESIGN_PREMIUM
                : PlatformSite::DESIGN_CLASSIC,
            'headline' => (string) ($this->settings?->get(PlatformSite::HEADLINE_KEY, '') ?? ''),
            'tagline' => (string) ($this->settings?->get(PlatformSite::TAGLINE_KEY, '') ?? ''),
            'trust' => (string) ($this->settings?->get(PlatformSite::TRUST_KEY, '') ?? ''),
        ];
        $content = $this->view->render('theme.index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Theme',
            'content' => $content,
        ]));
    }
}
