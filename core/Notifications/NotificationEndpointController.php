<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Auth\AuthGuard;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\NotificationModule;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class NotificationEndpointController
{
    /**
     * The subset of the hook-point catalog that actually fires a
     * notification dispatch (see Kernel::registerCoreBindings()) — the
     * admin picker only offers these, rather than every hook in
     * HookPoints::all(), so a saved subscription can never silently do
     * nothing because nobody wired that event to the dispatcher.
     */
    private const WIRED_EVENTS = [
        HookPoints::ORDER_PLACED,
        HookPoints::INVOICE_PAID,
        HookPoints::TICKET_OPEN,
        HookPoints::ORDER_FRAUD_FLAGGED,
    ];

    /** The two built-in providers — every other valid type is a registered NotificationModule slug (R24). */
    private const BUILT_IN_TYPES = ['slack', 'webhook'];

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly NotificationEndpointRepository $endpoints,
        private readonly ModuleManager $modules
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['endpoints' => $this->endpoints->all(), 'hookPoints' => self::WIRED_EVENTS, 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $type = (string) $request->input('type', '');
        $name = trim((string) $request->input('name', ''));
        $url = trim((string) $request->input('url', ''));
        $secret = trim((string) $request->input('secret', ''));
        $events = array_values(array_intersect((array) $request->input('events', []), self::WIRED_EVENTS));

        if (!in_array($type, $this->allowedTypes(), true) || $name === '' || $url === '' || $events === []) {
            return $this->render([
                'endpoints' => $this->endpoints->all(),
                'hookPoints' => self::WIRED_EVENTS,
                'error' => 'Type, name, URL, and at least one event are required.',
            ]);
        }

        $this->endpoints->create($type, $name, $url, $secret !== '' ? $secret : null, $events);

        return Response::redirect('/admin/notification-endpoints');
    }

    public function toggleActive(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $endpoint = $this->endpoints->find((int) $params['id']);

        if ($endpoint !== null) {
            $this->endpoints->setActive((int) $endpoint['id'], (int) $endpoint['is_active'] !== 1);
        }

        return Response::redirect('/admin/notification-endpoints');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->endpoints->delete((int) $params['id']);

        return Response::redirect('/admin/notification-endpoints');
    }

    /** @return array<int, string> every valid endpoint type: the built-ins plus every registered NotificationModule slug */
    private function allowedTypes(): array
    {
        return [...self::BUILT_IN_TYPES, ...array_keys($this->modules->allOfType(NotificationModule::class))];
    }

    /** @return array<string, array{name: string, description: string, version: string, author: string}> slug => metadata, for the type dropdown */
    private function registeredModules(): array
    {
        $result = [];

        foreach ($this->modules->allOfType(NotificationModule::class) as $slug => $module) {
            $result[$slug] = $module->metadata();
        }

        return $result;
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
        $content = $this->view->render('notifications.endpoints-index', [...$data, 'notificationModules' => $this->registeredModules()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Notification Endpoints',
            'content' => $content,
        ]));
    }
}
