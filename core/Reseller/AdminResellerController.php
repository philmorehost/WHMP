<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
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
        private readonly ResellerStoreService $stores,
        private readonly ResellerStoreLocator $locator,
        private readonly ResellerRetailPricing $retail,
        private readonly ResellerRetailPriceRepository $priceOverrides,
        private readonly ClientRepository $clients,
        private readonly ActivityLogger $activity,
        // Appended last: this controller is autowired, but keeping new deps at the
        // end is the standing rule here so a hand-built site can never silently
        // rebind the arguments after it.
        private readonly ResellerDomainSync $domainSync
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
            'stores' => $this->stores->all(),
            'platformHost' => $this->locator->platformHost(),
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

    // --- the storefront ----------------------------------------------------

    /**
     * One reseller's store. Addressed by CLIENT id, not store id, so the page
     * also works for a client who has a key but has not opened a store yet —
     * which is exactly when an admin needs to be able to see that.
     */
    public function store(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        return $this->renderStorePage($clientId, $store, $request);
    }

    /** Opens a store on a client's behalf — for a reseller who asked support to set one up. */
    public function openStore(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $result = $this->stores->openForClient(
            $clientId,
            (string) $request->input('store_name', ''),
            (string) $request->input('slug', '')
        );

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.store.opened',
            'reseller',
            (int) ($result['store']['id'] ?? 0),
            'Opened a reseller store for client #' . $clientId . ' at slug ' . (string) ($result['store']['slug'] ?? ''),
            $request->ip()
        );

        $this->session->flash('reseller_notice', 'Store opened.');

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    public function saveStoreBrand(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $result = $this->stores->saveBrand((int) $store['id'], [
            'brand_name' => $request->input('brand_name', ''),
            'logo_url' => $request->input('logo_url', ''),
            'favicon_url' => $request->input('favicon_url', ''),
            'primary_color' => $request->input('primary_color', ''),
        ]);

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $slug = trim((string) $request->input('slug', ''));

        if ($slug !== '') {
            $renamed = $this->stores->rename((int) $store['id'], $slug);

            if (!$renamed['success']) {
                $this->session->flash('reseller_error', (string) $renamed['error']);

                return Response::redirect('/admin/resellers/' . $clientId . '/store');
            }
        }

        $this->session->flash('reseller_notice', 'Store saved.');

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /**
     * An admin setting or clearing a store's domain from the store page.
     *
     * Reconciles the hosting panel as well, for the same reason the approval
     * queue does: a store whose domain changes must not be left with the OLD
     * hostname still on the server. That hostname would no longer match any
     * store, so it would answer with our own shop at our own prices.
     */
    public function claimStoreDomain(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $domain = trim((string) $request->input('custom_domain', ''));

        if ($domain === '') {
            $this->stores->releaseDomain((int) $store['id']);
            $outcome = $this->domainSync->syncStore((int) $store['id']);

            $this->session->flash('reseller_notice', 'Custom domain released. ' . $outcome['message']);

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $result = $this->stores->claimDomain((int) $store['id'], $domain);

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        if (($result['unchanged'] ?? false) === true) {
            $this->session->flash('reseller_notice', 'That is already the store\'s domain — nothing was changed.');

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $outcome = $this->domainSync->syncStore((int) $store['id']);
        $removed = (string) ($outcome['removed'] ?? '');

        $this->session->flash(
            'reseller_notice',
            ($removed !== ''
                ? 'Domain claimed. ' . $removed . ' was removed from the hosting panel and ' . $result['domain'] . ' replaces it. '
                : 'Domain claimed. ')
            . 'It will not be served until the DNS record below is verified and the request is approved.'
        );

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /**
     * Admins can verify too, and can see exactly what DNS returned — so a
     * support ticket about a failing domain can be answered without guessing.
     */
    public function verifyStoreDomain(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $result = $this->stores->verifyDomain((int) $store['id']);

        if ($result['verified']) {
            $this->session->flash(
                'reseller_notice',
                'Domain verified via ' . (string) $result['method'] . ' — the store is live on '
                . (string) $store['custom_domain'] . '.'
            );

            $this->activity->log(
                'admin',
                $this->adminId(),
                'reseller.store.domain_verified',
                'reseller',
                (int) $store['id'],
                'Verified store domain ' . (string) $store['custom_domain'] . ' via ' . (string) $result['method'],
                $request->ip()
            );

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $this->session->flash('reseller_verification', [
            'domain' => (string) ($store['custom_domain'] ?? ''),
            'record_name' => (string) ($result['record_name'] ?? ''),
            'expected' => (string) ($result['expected'] ?? ''),
            'found' => (array) ($result['found'] ?? []),
            'error' => $result['error'],
        ]);

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /**
     * Forces the domain verification, or takes it back.
     *
     * An override is a decision about who controls a hostname, so it is recorded
     * as 'manual' on the store itself (never as a DNS proof) and in the activity
     * log with the admin who made it. Needed when a provider cannot serve the
     * TXT record DomainVerifier wants, or when a verification turns out to have
     * been wrong.
     */
    public function overrideStoreDomain(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $verified = (string) $request->input('verified', '1') === '1';
        $result = $this->stores->overrideDomainVerification((int) $store['id'], $verified);

        if (!$result['success']) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect('/admin/resellers/' . $clientId . '/store');
        }

        $domain = (string) ($store['custom_domain'] ?? '');

        $this->session->flash(
            'reseller_notice',
            $verified
                ? 'Marked ' . $domain . ' as verified without checking DNS. It is recorded as a manual override, and the store '
                    . 'will serve that domain as soon as it points here.'
                : 'Verification removed from ' . $domain . '. The store stays reachable on its platform address only.'
        );

        $this->activity->log(
            'admin',
            $this->adminId(),
            $verified ? 'reseller.store.domain_verification_overridden' : 'reseller.store.domain_verification_cleared',
            'reseller',
            (int) $store['id'],
            ($verified ? 'Forced verification of ' : 'Removed verification from ') . $domain . ' without checking DNS',
            $request->ip()
        );

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /** Taking a store offline: its customers see 503 rather than our shop at our prices. */
    public function setStoreStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);
        $status = (string) $request->input('status', 'active');

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $result = $this->stores->setStatus((int) $store['id'], $status);

        $this->session->flash(
            $result['success'] ? 'reseller_notice' : 'reseller_error',
            $result['success']
                ? ($status === 'active' ? 'Store reactivated.' : 'Store suspended — its domain now returns a 503.')
                : (string) $result['error']
        );

        if ($result['success']) {
            $this->activity->log(
                'admin',
                $this->adminId(),
                $status === 'active' ? 'reseller.store.activated' : 'reseller.store.suspended',
                'reseller',
                (int) $store['id'],
                'Set reseller store #' . $store['id'] . ' (client #' . $clientId . ') to ' . $status,
                $request->ip()
            );
        }

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /**
     * The store's support chat, set on the reseller's behalf.
     *
     * Exists mostly for support: a reseller who cannot work the field can be walked
     * through it, and their live store does not have to wait on them. The reseller
     * owns the page and can change it again afterwards.
     */
    public function saveStoreChat(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) ($params['clientId'] ?? 0);
        $store = $this->stores->forClient($clientId);

        if ($store === null) {
            $this->session->flash('reseller_error', 'That client does not have a store.');

            return Response::redirect('/admin/resellers');
        }

        $result = $this->stores->saveChat((int) $store['id'], [
            'support_whatsapp' => $request->input('support_whatsapp', ''),
            'tawk_property_id' => $request->input('tawk_property_id', ''),
            'tawk_widget_id' => $request->input('tawk_widget_id', ''),
        ]);

        $this->activity->log(
            'admin',
            $this->adminId(),
            'reseller.store.chat',
            'reseller',
            (int) $store['id'],
            'Set the support chat for store #' . (int) $store['id']
                . ($result['success'] ? '' : ' — refused: ' . (string) $result['error']),
            $request->ip()
        );

        $this->session->flash(
            $result['success'] ? 'reseller_notice' : 'reseller_error',
            $result['success'] ? 'Support chat saved.' : (string) $result['error']
        );

        return Response::redirect('/admin/resellers/' . $clientId . '/store');
    }

    /** @param array<string, mixed>|null $store */
    private function renderStorePage(int $clientId, ?array $store, Request $request): Response
    {
        return $this->render('reseller.admin-store', [
            'clientId' => $clientId,
            'store' => $store,
            'client' => $this->clients->find($clientId),
            'platformHost' => $this->locator->platformHost(),
            'platformUrl' => $store === null ? null : $this->stores->platformUrl($store),
            'markup' => $store === null ? 0.0 : $this->retail->markupFor($store),
            'overrideCount' => $store === null ? 0 : $this->priceOverrides->countFor((int) $store['id']),
            'discounts' => $this->settings->all(),
            'recordName' => $store === null || ($store['custom_domain'] ?? null) === null
                ? null
                : '_codevault-verify.' . $store['custom_domain'],
            'goLive' => $store === null ? [] : $this->stores->goLiveChecklist($store),
            'chat' => ResellerChat::formValues($store),
            'error' => $this->session->pullFlash('reseller_error'),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'verification' => $this->session->pullFlash('reseller_verification'),
        ]);
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
