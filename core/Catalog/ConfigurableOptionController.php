<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ConfigurableOptionController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ConfigurableOptionGroupRepository $groups,
        private readonly ConfigurableOptionRepository $options,
        private readonly ConfigurableOptionPricingRepository $pricing
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('catalog.option-groups-index', ['groups' => $this->groups->all()]);
    }

    public function storeGroup(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $this->groups->create($name);
        }

        return Response::redirect('/admin/configurable-options');
    }

    public function updateGroup(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $this->groups->update((int) $params['id'], $name);
        }

        return Response::redirect('/admin/configurable-options');
    }

    public function destroyGroup(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->groups->delete((int) $params['id']);

        return Response::redirect('/admin/configurable-options');
    }

    public function bulkDeleteGroups(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $groupIds = array_map('intval', (array) $request->input('selected_groups', []));

        foreach ($groupIds as $groupId) {
            $this->groups->delete($groupId);
        }

        return Response::redirect('/admin/configurable-options');
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $group = $this->groups->find((int) $params['id']);

        if ($group === null) {
            return Response::html('404 Not Found', 404);
        }

        $options = $this->options->forGroup((int) $group['id']);

        foreach ($options as &$option) {
            $option['pricing'] = $this->pricing->forOption((int) $option['id']);
        }
        unset($option);

        return $this->render('catalog.option-group-show', [
            'group' => $group,
            'options' => $options,
            'cycles' => BillingCycle::labels(),
        ]);
    }

    public function storeOption(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $groupId = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $optionId = $this->options->create($groupId, $name);

            $prices = (array) $request->input('price', []);

            foreach (BillingCycle::keys() as $cycle) {
                if (isset($prices[$cycle]) && $prices[$cycle] !== '') {
                    $this->pricing->setPricing($optionId, $cycle, (float) $prices[$cycle]);
                }
            }
        }

        return Response::redirect("/admin/configurable-options/{$groupId}");
    }

    public function destroyOption(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->options->delete((int) $params['optionId']);

        return Response::redirect("/admin/configurable-options/{$params['id']}");
    }

    public function updateOption(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $groupId = (int) $params['id'];
        $optionId = (int) $params['optionId'];
        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $container = \CodeVault\Support\App::container();
            $db = $container->make(\CodeVault\Database::class);
            $db->update("UPDATE configurable_options SET name = ? WHERE id = ?", [$name, $optionId]);

            $prices = (array) $request->input('price', []);
            foreach (BillingCycle::keys() as $cycle) {
                if (isset($prices[$cycle])) {
                    $this->pricing->setPricing($optionId, $cycle, (float) $prices[$cycle]);
                }
            }
        }

        return Response::redirect("/admin/configurable-options/{$groupId}");
    }

    public function bulkDeleteOptions(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $groupId = (int) $params['id'];
        $optionIds = array_map('intval', (array) $request->input('selected_options', []));

        foreach ($optionIds as $optionId) {
            $this->options->delete($optionId);
        }

        return Response::redirect("/admin/configurable-options/{$groupId}");
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PRODUCTS_MANAGE)) {
            return Response::html('403 Forbidden — missing products.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Configurable Options',
            'content' => $content,
        ]));
    }
}
