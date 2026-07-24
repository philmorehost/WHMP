<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

final class GatewayController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly PaymentGatewayRepository $gateways,
        private readonly Database $db,
        private readonly ModuleManager $modules,
        private readonly Config $config
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $logFile = $this->config->basePath('storage/logs/payment_gateways.log');
        $logs = [];
        if (is_file($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $logs = array_reverse(array_slice($lines, -100));
        }

        return $this->render('billing.gateways-index', [
            'gateways' => $this->gateways->all(),
            'baseUrl' => rtrim((string) $this->config->env('APP_URL', ''), '/'),
            'gatewayLogs' => $logs,
        ]);
    }

    public function toggle(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $gateway = $this->gateways->findBySlug((string) $params['slug']);

        if ($gateway !== null) {
            $this->gateways->setEnabled($gateway['slug'], !$gateway['is_enabled']);
        }

        return Response::redirect('/admin/gateways');
    }

    /**
     * Generic across every gateway module (blueprint §10 — Manual was the
     * only gateway when this only handled 'bank_details'; R12 added
     * Paystack/Flutterwave, each with their own config fields, so this
     * now reads the target module's own configOptions() rather than
     * hardcoding one gateway's field name).
     */
    public function updateConfig(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $module = $this->modules->get(GatewayModule::class, $slug);

        if (!$module instanceof GatewayModule) {
            return Response::redirect('/admin/gateways');
        }

        $gatewayRow = $this->gateways->findBySlug($slug);
        $existingConfig = $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];
        $config = [];

        foreach ($module->configOptions() as $key => $option) {
            $submitted = trim((string) $request->input($key, ''));

            // A blank password-type field on re-save means "leave it as
            // is" — an admin re-opening this form shouldn't have to
            // re-paste a secret key just to change an unrelated field.
            $config[$key] = ($submitted === '' && ($option['type'] ?? '') === 'password')
                ? ($existingConfig[$key] ?? '')
                : $submitted;
        }

        $config['gateway_currency'] = strtoupper(trim((string) $request->input('gateway_currency', 'NGN'))) ?: 'NGN';

        $this->db->update(
            'UPDATE payment_gateways SET config = ?, updated_at = ? WHERE slug = ?',
            [json_encode($config), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );

        return Response::redirect('/admin/gateways');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::GATEWAYS_MANAGE)) {
            return Response::html('403 Forbidden — missing gateways.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Payment Gateways',
            'content' => $content,
        ]));
    }
}
