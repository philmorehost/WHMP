<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin side of the reseller programme: the discounts resellers get, and the
 * keys clients have been issued.
 *
 * The discount is stored as a percentage and read by ResellerPricing on every
 * catalogue view and every API call, so it is the one number here that changes
 * money. It is clamped to 0–100 by ResellerSettings rather than by the form —
 * a typo of "1000" must not turn a reseller price negative, and a value that
 * arrives via a replayed request never passes through the form at all.
 *
 * Note this page only ever *quotes* the discount. Checkout still charges list
 * price: a reseller is expected to invoice their own customer, not to have this
 * platform bill the customer at reseller rates. That is a deliberate scoping
 * decision, not an unfinished edge.
 */
final class AdminResellerController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerSettings $settings,
        private readonly ResellerCredentialService $credentials,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $resellers = $this->credentials->all();

        return $this->render('reseller.admin-index', [
            'discounts' => $this->settings->all(),
            'resellers' => $resellers,
            'activeCount' => count(array_filter($resellers, static fn (array $r): bool => (int) ($r['active'] ?? 0) === 1)),
            'error' => $this->session->pullFlash('reseller_error'),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'docsUrl' => '/admin/resellers/docs',
        ]);
    }

    public function saveDiscounts(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $service = (float) $request->input('discount_services', 0);
        $domains = (float) $request->input('discount_domains', 0);

        $this->settings->save($service, $domains);

        $saved = $this->settings->all();

        // Say what was actually stored when the input was clamped, rather than
        // reporting "saved" for a number the admin will not find in the form.
        $notice = 'Reseller discounts saved: '
            . ResellerSettings::formatPercent($saved['service']) . '% on services, '
            . ResellerSettings::formatPercent($saved['domain']) . '% on domains.';

        if ($service !== ResellerSettings::clampPercent($service)
            || $domains !== ResellerSettings::clampPercent($domains)) {
            $notice .= ' (Values outside 0–100 were adjusted.)';
        }

        $this->session->flash('reseller_notice', $notice);

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.discounts.updated',
            null,
            null,
            'Set reseller discounts to ' . ResellerSettings::formatPercent($saved['service']) . '% (services) and '
                . ResellerSettings::formatPercent($saved['domain']) . '% (domains)',
            $request->ip()
        );

        return Response::redirect('/admin/resellers');
    }

    /** Switch a reseller's key off or back on. The domain it was issued for is kept. */
    public function toggle(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $enable = (string) $request->input('enabled', '0') === '1';

        if (!$this->credentials->setEnabled($clientId, $enable)) {
            $this->session->flash('reseller_error', 'That client does not have a reseller API key.');

            return Response::redirect('/admin/resellers');
        }

        $this->session->flash('reseller_notice', $enable
            ? 'Reseller key enabled.'
            : 'Reseller key disabled — it can no longer authenticate.');

        $this->activity->log(
            'admin',
            $this->adminId(),
            $enable ? 'reseller.key.enabled' : 'reseller.key.disabled',
            'api_credential',
            null,
            ($enable ? 'Enabled' : 'Disabled') . ' the reseller API key for client #' . $clientId,
            $request->ip()
        );

        return Response::redirect('/admin/resellers');
    }

    /** The same API reference resellers see, reachable from the admin sidebar. */
    public function docs(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('reseller.docs', [
            'credential' => null,
            'backUrl' => '/admin/resellers',
            'backLabel' => 'Back to resellers',
            'baseUrl' => ApiDocumentation::BASE_URL,
            'authHeader' => ApiDocumentation::AUTH_HEADER,
            'endpoints' => ApiDocumentation::endpoints(),
            'scopes' => ApiDocumentation::scopes(),
            'errorCodes' => ApiDocumentation::errorCodes(),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Reseller API Documentation',
            'content' => $content,
        ]));
    }

    /** The signed-in admin's id for the activity log, or null when there isn't one. */
    private function adminId(): ?int
    {
        $admin = $this->guard->current();

        return $admin === null ? null : (int) $admin['id'];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Resellers',
            'content' => $content,
        ]));
    }
}
