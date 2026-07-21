<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin AI-management dashboard: configure the provider/API key, toggle
 * each AI feature on or off, and monitor usage (calls + token spend). The
 * API key is stored via AiSettings (settings table) and only ever shown
 * masked; usage figures come from AiUsageRepository.
 */
final class AiAdminController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AiSettings $settings,
        private readonly AiUsageRepository $usage
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['saved' => $request->query('saved') === '1', 'error' => null]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $provider = trim((string) $request->input('provider', 'deepseek'));
        $model = trim((string) $request->input('model', ''));
        $apiKey = (string) $request->input('api_key', '');

        /** @var array<int, string> $enabled */
        $enabled = (array) $request->input('features', []);
        $enabled = array_values(array_intersect(array_keys(AiSettings::FEATURES), $enabled));

        $this->settings->save($apiKey !== '' ? $apiKey : null, $provider, $model, $enabled);

        return Response::redirect('/admin/ai?saved=1');
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

    /** @param array<string, mixed> $extra */
    private function render(array $extra): Response
    {
        $features = [];
        foreach (AiSettings::FEATURES as $slug => $label) {
            $features[] = ['slug' => $slug, 'label' => $label, 'enabled' => $this->settings->isFeatureEnabled($slug)];
        }

        $content = $this->view->render('ai.admin', array_merge([
            'provider' => $this->settings->provider(),
            'model' => $this->settings->model(),
            'maskedKey' => $this->settings->maskedKey(),
            'hasKey' => $this->settings->hasKey(),
            'features' => $features,
            'totals' => $this->usage->totals(),
            'byFeature' => $this->usage->byFeature(),
            'recent' => $this->usage->recent(20),
        ], $extra));

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — AI Copilot',
            'content' => $content,
        ]));
    }
}
