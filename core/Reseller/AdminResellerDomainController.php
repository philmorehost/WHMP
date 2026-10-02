<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * The domain request queue: a reseller asks for a hostname, an admin answers.
 *
 * WHY A SEPARATE CONTROLLER (and not more methods on AdminResellerController):
 * that controller is large and is constructed BY HAND in several tests, so a new
 * constructor dependency there is a test edit for no benefit. Same reasoning as
 * AdminResellerBillingController. Nothing here is unique to this feature beyond
 * the two decisions and the provisioning call.
 *
 * THE DECISION AND THE PROVISIONING ARE TWO STEPS, ON PURPOSE.
 *
 * Approving records the human decision. It then *asks* the hosting panel to add
 * the domain, and records what the panel said. If the panel refuses, the
 * approval stands and the error is stored — because "an admin approved this" and
 * "the server accepted it" are different facts, and collapsing them would either
 * hide a failure or undo a deliberate decision.
 *
 * Approval does NOT make the domain serve. Serving still requires the DNS proof
 * (`domain_verified_at`), which is a separate claim about fact rather than
 * intent. See migration 0198 for why those are kept apart.
 */
final class AdminResellerDomainController
{
    /** Settings keys the provisioner reads, in one place so the form and it cannot drift. */
    public const SETTING_MODE = 'reseller.domain_provisioning';
    public const SETTING_SERVER = 'reseller.cpanel_server_id';
    public const SETTING_ACCOUNT = 'reseller.cpanel_account_user';
    public const SETTING_DOCROOT = 'reseller.cpanel_docroot';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerDomainProvisioner $provisioner,
        private readonly ServerRepository $servers,
        private readonly SettingsRepository $settings,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reseller.admin-domains', [
            'pending' => $this->stores->pendingDomainRequests(),
            'decided' => $this->stores->decidedDomainRequests(30),
            'servers' => $this->servers->all(),
            'settings' => [
                'mode' => $this->provisioner->mode(),
                'server_id' => (string) ($this->settings->get(self::SETTING_SERVER, '') ?? ''),
                'account' => (string) ($this->settings->get(self::SETTING_ACCOUNT, '') ?? ''),
                'docroot' => (string) ($this->settings->get(self::SETTING_DOCROOT, '') ?? ''),
            ],
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Approve the request, then try to add the domain on the hosting panel.
     *
     * The approval is committed FIRST and separately: if the panel call fails,
     * the decision is not rolled back — it happened, and the error is recorded
     * next to it so the admin can act on it.
     */
    public function approve(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $store = $this->stores->find((int) ($params['storeId'] ?? 0));

        if ($store === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/domains');
        }

        $note = trim((string) $request->input('note', ''));

        // Guarded inside the UPDATE, so a second admin deciding at the same
        // moment loses cleanly instead of overwriting the first decision.
        if (!$this->stores->approveDomain((int) $store['id'], $this->adminId(), $note === '' ? null : $note)) {
            $this->session->flash(
                'reseller_error',
                'That request was already decided by someone else — nothing was changed.'
            );

            return Response::redirect('/admin/resellers/domains');
        }

        $outcome = $this->provisioner->provision($store);

        if ($outcome['ok']) {
            $this->stores->markDomainProvisioned((int) $store['id']);
        } elseif (!($outcome['skipped'] ?? false)) {
            $this->stores->recordDomainProvisionError((int) $store['id'], $outcome['message']);
        }

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.approved',
            null,
            null,
            'Approved custom domain ' . (string) $store['custom_domain'] . ' for store #' . (int) $store['id']
                . ($outcome['skipped'] ? ' (provisioning off)' : ' — ' . $outcome['message']),
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Approved ' . (string) $store['custom_domain'] . '. ' . $outcome['message']
        );

        return Response::redirect('/admin/resellers/domains');
    }

    /**
     * Refuse the request. The reason is required.
     *
     * Required because the reseller is shown it and has to be able to act on it:
     * "rejected" with no reason is a dead end that generates a support ticket,
     * which is the cost the queue exists to avoid. The repository also clears any
     * DNS verification, so a refusal stops a domain that was somehow already
     * being served.
     */
    public function reject(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $store = $this->stores->find((int) ($params['storeId'] ?? 0));

        if ($store === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/domains');
        }

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            $this->session->flash(
                'reseller_error',
                'A reason is required — the reseller is shown it and has to be able to correct the request.'
            );

            return Response::redirect('/admin/resellers/domains');
        }

        if (!$this->stores->rejectDomain((int) $store['id'], $this->adminId(), $reason)) {
            $this->session->flash(
                'reseller_error',
                'That request was already decided by someone else — nothing was changed.'
            );

            return Response::redirect('/admin/resellers/domains');
        }

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.rejected',
            null,
            null,
            'Rejected custom domain ' . (string) $store['custom_domain'] . ' for store #' . (int) $store['id']
                . ': ' . $reason,
            $request->ip()
        );

        $this->session->flash('reseller_notice', 'Refused ' . (string) $store['custom_domain'] . '. The reseller can see the reason and submit again.');

        return Response::redirect('/admin/resellers/domains');
    }

    /**
     * Save the provisioning settings.
     *
     * The mode is validated against the two the provisioner understands, rather
     * than stored as typed: an unrecognised mode would fall back to 'off' at
     * provision time, and a setting that says 'on' while behaving as 'off' is
     * worse than one that says so.
     */
    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $mode = (string) $request->input('mode', ResellerDomainProvisioner::MODE_OFF);

        if (!in_array($mode, [ResellerDomainProvisioner::MODE_OFF, ResellerDomainProvisioner::MODE_CPANEL], true)) {
            $mode = ResellerDomainProvisioner::MODE_OFF;
        }

        $this->settings->set(self::SETTING_MODE, $mode);
        $this->settings->set(self::SETTING_SERVER, trim((string) $request->input('server_id', '')));
        $this->settings->set(self::SETTING_ACCOUNT, trim((string) $request->input('account', '')));
        $this->settings->set(self::SETTING_DOCROOT, trim((string) $request->input('docroot', '')));

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.settings',
            null,
            null,
            'Updated reseller domain provisioning settings (mode: ' . $mode . ')',
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            $mode === ResellerDomainProvisioner::MODE_CPANEL
                ? 'Automatic provisioning is ON. Approving a domain will now call the hosting panel.'
                : 'Automatic provisioning is off — approving a domain only records the decision, and the domain must still be added on the server by hand.'
        );

        return Response::redirect('/admin/resellers/domains');
    }

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
            'title' => 'CodeVault Admin — Reseller domains',
            'content' => $content,
        ]));
    }
}
