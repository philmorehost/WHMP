<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin editor for per-language string overrides (blueprint §5). Lists
 * every key from that language's file catalog next to its current
 * effective value (override if one exists, else the file default) so
 * staff can correct a translation without touching version-controlled files.
 */
final class TranslationOverrideController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly LanguageRepository $languages,
        private readonly TranslationOverrideRepository $overrides,
        private readonly LocalizationService $localization
    ) {
    }

    public function edit(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $language = $this->languages->find((int) $params['id']);

        if ($language === null) {
            return Response::html('404 Not Found', 404);
        }

        $translation = $this->localization->translationFor($language);
        $overrideMap = $this->overrides->mapForLanguage((int) $language['id']);
        $baseCatalog = require dirname(__DIR__, 2) . '/resources/lang/en.php';

        $rows = [];

        foreach (array_keys($baseCatalog) as $key) {
            $rows[] = [
                'key' => $key,
                'default' => $baseCatalog[$key],
                'current' => $translation->get($key),
                'overridden' => array_key_exists($key, $overrideMap),
            ];
        }

        return $this->render(['language' => $language, 'rows' => $rows]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $languageId = (int) $params['id'];

        foreach ((array) $request->input('value', []) as $key => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $this->overrides->set($languageId, (string) $key, $value);
            }
        }

        return Response::redirect("/admin/languages/{$languageId}/translations");
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
        $content = $this->view->render('localization.translations-edit', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Translations',
            'content' => $content,
        ]));
    }
}
