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
        private readonly ResellerDomainSync $domainSync,
        private readonly ServerRepository $servers,
        private readonly SettingsRepository $settings,
        private readonly ActivityLogger $activity,
        // Appended last: taken directly for the panel DIAGNOSTIC, which is a
        // property of the panel rather than of any one store.
        private readonly ResellerDomainProvisioner $provisioner
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
            // Hostnames on the panel that no store is approved to use — removals
            // the automation could not finish (a failed call, or provisioning
            // being off). Listed separately because they are the dangerous ones:
            // they answer for a host that resolves to the PLATFORM shop.
            'outstanding' => $this->stores->outstandingPanelDomains(),
            // One-shot: only present when somebody pressed the button, because
            // the checks below actually call the hosting panel.
            'panelCheck' => $this->session->pullFlash('reseller_panel_check', []),
            'servers' => $this->servers->all(),
            'settings' => [
                'mode' => $this->domainSync->mode(),
                'server_id' => (string) ($this->settings->get(self::SETTING_SERVER, '') ?? ''),
                'account' => (string) ($this->settings->get(self::SETTING_ACCOUNT, '') ?? ''),
                'docroot' => (string) ($this->settings->get(self::SETTING_DOCROOT, '') ?? ''),
            ],
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Approve the request, then bring the hosting panel into line with it.
     *
     * The approval is committed FIRST and separately: if the panel call fails,
     * the decision is not rolled back — it happened, and the error is recorded
     * next to it so the admin can act on it.
     *
     * The panel step is a RECONCILIATION, not an add. If this store previously
     * had a different domain approved and provisioned, approving a new one takes
     * the old hostname off before the new one goes on — see ResellerDomainSync.
     * Adding without removing is how a replaced domain ends up permanently on the
     * server, answering as the platform shop.
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

        // Re-read the store: the decision above is this step's INPUT, and the row
        // in hand still says 'pending'. Syncing against that would refuse to
        // provision the domain the admin just approved.
        $outcome = $this->domainSync->syncStore((int) $store['id']);

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

        // A refusal has to take the domain OFF the panel too, not merely stop it
        // being served. Anything added while the domain was approved would keep
        // answering for a hostname no store claims any more, and would resolve to
        // the platform shop at platform prices.
        $outcome = $this->domainSync->syncStore((int) $store['id']);

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.rejected',
            null,
            null,
            'Rejected custom domain ' . (string) $store['custom_domain'] . ' for store #' . (int) $store['id']
                . ': ' . $reason . ' — ' . $outcome['message'],
            $request->ip()
        );

        $this->session->flash(
            'reseller_notice',
            'Refused ' . (string) $store['custom_domain'] . '. The reseller can see the reason and submit again. '
                . $outcome['message']
        );

        return Response::redirect('/admin/resellers/domains');
    }

    /**
     * Create the domain on the hosting panel, again.
     *
     * The retry lever for the case this feature kept getting wrong: an approval
     * that could not reach the panel, or that the panel refused, leaves the
     * decision recorded and the domain NOT created. Nothing used to be able to
     * try again other than saving the whole approval over.
     *
     * Refuses unless the domain is APPROVED, because approval is the human gate
     * (migration 0198) and a manual button that bypassed it would make the gate
     * decorative. It goes through the same reconciliation as an approval, so if
     * the store's previous domain is somehow still on the panel it comes off
     * first rather than ending up alongside the new one.
     */
    public function provisionNow(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $store = $this->stores->find((int) ($params['storeId'] ?? 0));

        if ($store === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/domains');
        }

        $domain = trim((string) ($store['custom_domain'] ?? ''));

        if ($domain === '') {
            $this->session->flash('reseller_error', 'That store has no custom domain to create.');

            return Response::redirect('/admin/resellers/domains');
        }

        if ((string) ($store['domain_status'] ?? 'none') !== 'approved') {
            $this->session->flash(
                'reseller_error',
                $domain . ' has not been approved, so it will not be sent to the hosting panel. '
                    . 'Approve the request first — that is the gate that exists to stop a hostname we have not '
                    . 'reviewed being served.'
            );

            return Response::redirect('/admin/resellers/domains');
        }

        $outcome = $this->domainSync->syncStore((int) $store['id']);

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.provision_retry',
            null,
            null,
            'Manual panel provisioning for store #' . (int) $store['id'] . ' (' . $domain . '): ' . $outcome['message'],
            $request->ip()
        );

        $this->session->flash($outcome['ok'] ? 'reseller_notice' : 'reseller_error', $outcome['message']);

        return Response::redirect('/admin/resellers/domains');
    }

    /**
     * Ask the hosting panel what it can actually do.
     *
     * A failure here is otherwise nearly undiagnosable from inside the
     * application: the only symptom is an approval carrying an error message,
     * and the information that identifies the problem — a missing module file, a
     * refused API version, an unreachable server — lives in the panel's reply.
     * So this is a button that reports the raw answers.
     */
    public function runDiagnostic(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $rows = $this->provisioner->diagnose();

        $this->session->flash('reseller_panel_check', $rows);

        $failed = count(array_filter($rows, static fn (array $row): bool => $row['ok'] === false));

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.diagnostic',
            null,
            null,
            'Ran the reseller domain panel check: ' . count($rows) . ' check(s), ' . $failed . ' failed.',
            $request->ip()
        );

        return Response::redirect('/admin/resellers/domains');
    }

    /**
     * Take a store's domain back off the hosting panel.
     *
     * The manual lever for the two cases the automation cannot finish by itself:
     * a removal that FAILED, and one that was SKIPPED because provisioning is
     * switched off. Both leave a hostname on the server that no store is approved
     * to use, so there has to be a button rather than a line in a log — the whole
     * risk of that state is that nobody notices it.
     */
    public function unprovision(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $store = $this->stores->find((int) ($params['storeId'] ?? 0));

        if ($store === null) {
            $this->session->flash('reseller_error', 'That store no longer exists.');

            return Response::redirect('/admin/resellers/domains');
        }

        $outcome = $this->domainSync->removeStoreDomain($store);

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.domain.unprovisioned',
            null,
            null,
            'Removed the panel domain for store #' . (int) $store['id']
                . ' (' . (string) ($store['domain_provisioned_host'] ?? '') . '): ' . $outcome['message'],
            $request->ip()
        );

        $this->session->flash($outcome['ok'] ? 'reseller_notice' : 'reseller_error', $outcome['message']);

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
