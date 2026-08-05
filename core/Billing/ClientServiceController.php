<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketService;
use CodeVault\View;
use CodeVault\Activity\ActivityLogger;

/**
 * My Services (blueprint §4.1): status, usage, SSO-to-panel, cancel.
 *
 * Every management action on this page goes through ProvisioningService to
 * the server module that actually owns the machine. That is worth stating
 * because it did not used to: `power()`, `vnc()`, `backup()` and
 * `restore()` previously wrote an activity-log row and redirected with a
 * hardcoded success string — no API call, no failure path, and messages
 * naming specifics that were never fetched ("Connection port: 5901 (SSL
 * Encrypted)"). A client could power off a server that was already gone and
 * be told it worked. Anything that cannot be performed against the real API
 * now either reports the vendor's own error or is routed to a support
 * ticket; nothing on this page reports success it did not observe.
 */
final class ClientServiceController
{
    private const KIND_SHARED = 'shared';
    private const KIND_VPS = 'vps';
    private const KIND_DEDICATED = 'dedicated';

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly ServerRepository $servers,
        private readonly CurrencyService $currency,
        private readonly CancellationRequestRepository $cancellations,
        private readonly InvoiceRepository $invoices,
        private readonly ActivityLogger $activity,
        private readonly \CodeVault\Domains\DomainSettings $domainSettings,
        private readonly TicketService $tickets,
        private readonly DepartmentRepository $departments,
        private readonly AddonModuleRepository $addons,
        private readonly Database $db
    ) {
    }

    private const DOMAIN_CHANGER_SLUG = 'domain-changer';

    /** RFC-1035-ish hostname check — good enough to reject garbage before it ever reaches WHM. */
    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/';

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $services = $this->services->forClient((int) $client['id']);

        // A standalone domain registration rides on the hidden "Domain
        // Registration" carrier service (see DomainService::carrierProductId()),
        // so it would otherwise appear here as a fake "service" whose only real
        // management surface is the domain manager. Domains have their own page
        // (/client/domains) — keep them out of the services list entirely.
        $carrierProductId = (int) ($this->db->selectOne(
            "SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1"
        )['id'] ?? 0);

        if ($carrierProductId > 0) {
            $services = array_values(array_filter(
                $services,
                static fn (array $svc): bool => (int) $svc['product_id'] !== $carrierProductId
            ));
        }

        return $this->page('billing.client-services-index', [
            'services' => $services,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        return $this->renderService(
            $service,
            $client,
            // Actions redirect back here carrying their outcome. show() used
            // to read $params['message'] — route params, which never carry a
            // query string — so every redirect message was silently dropped.
            $request->query('msg') !== null ? (string) $request->query('msg') : null,
            $request->query('err') !== null ? (string) $request->query('err') : null
        );
    }

    public function sso(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->provisioning->singleSignOn((int) $service['id']);

        if (!$result['success']) {
            return $this->back((int) $service['id'], null, $result['message']);
        }

        // A VPS module hands back a vnc:// URI rather than an https panel
        // URL — a browser redirect would dead-end, so surface it as text the
        // client can paste into a VNC viewer.
        if (str_starts_with((string) $result['url'], 'vnc://')) {
            return $this->back((int) $service['id'], 'VNC console ready — connect with a VNC client to ' . $result['url']);
        }

        return Response::redirect($result['url']);
    }

    public function cancelForm(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        if ($this->cancellations->findPendingForService((int) $service['id']) !== null) {
            return Response::redirect("/client/services/{$service['id']}");
        }

        return $this->page('billing.client-service-cancel', [
            'service' => $service,
            'currency' => $this->currency->resolveForClient($client),
        ]);
    }

    public function cancel(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        if ($this->cancellations->findPendingForService((int) $service['id']) !== null) {
            return Response::redirect('/client/services');
        }

        $type = (string) $request->input('type', 'end_of_period');
        $reason = trim((string) $request->input('reason', ''));

        if ($type === 'immediate') {
            $this->provisioning->terminate((int) $service['id']);
            $this->services->cancel((int) $service['id']);
            $this->invoices->cancelUnpaidForService((int) $service['id']);

            $this->activity->log(
                'client',
                (int) $client['id'],
                'service.cancelled_immediate',
                'service',
                (int) $service['id'],
                "Client cancelled service #{$service['id']} immediately. Reason: {$reason}",
                $request->ip()
            );
        } else {
            $this->cancellations->createRequest((int) $service['id'], $type, $reason, (int) $client['id']);
            $this->invoices->cancelUnpaidForService((int) $service['id']);

            $this->activity->log(
                'client',
                (int) $client['id'],
                'service.cancellation_requested',
                'service',
                (int) $service['id'],
                "Client requested end of period cancellation for service #{$service['id']}. Reason: {$reason}",
                $request->ip()
            );
        }

        return Response::redirect('/client/services');
    }

    /**
     * Start / stop / restart against the real hypervisor. The module returns
     * a queued-action acknowledgement, not a completed one, and the message
     * says so rather than claiming the machine has finished changing state.
     */
    public function power(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $action = strtolower(trim((string) $request->input('action', 'restart')));

        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->back((int) $service['id'], null, 'Unrecognised power action.');
        }

        $result = $this->provisioning->power((int) $service['id'], $action);

        $this->activity->log(
            'client',
            (int) $client['id'],
            "server.power_{$action}",
            'service',
            (int) $service['id'],
            sprintf(
                'Client requested %s on service #%d — %s: %s',
                $action,
                (int) $service['id'],
                $result['success'] ? 'accepted' : 'rejected',
                $result['message']
            ),
            $request->ip()
        );

        return $this->backFrom((int) $service['id'], $result);
    }

    /** Reads live VNC connection info; never invents a port. */
    public function vnc(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->provisioning->singleSignOn((int) $service['id']);

        $this->activity->log(
            'client',
            (int) $client['id'],
            'vps.vnc_requested',
            'service',
            (int) $service['id'],
            "Client requested VNC console for service #{$service['id']}: " . $result['message'],
            $request->ip()
        );

        if (!$result['success']) {
            return $this->back((int) $service['id'], null, $result['message']);
        }

        return $this->back((int) $service['id'], 'VNC console ready — connect with a VNC client to ' . $result['url']);
    }

    /** Queues a real on-demand snapshot. */
    public function backup(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->provisioning->createBackup((int) $service['id']);

        $this->activity->log(
            'client',
            (int) $client['id'],
            'vps.backup_requested',
            'service',
            (int) $service['id'],
            sprintf('Client requested a snapshot of service #%d — %s: %s', (int) $service['id'], $result['success'] ? 'queued' : 'rejected', $result['message']),
            $request->ip()
        );

        return $this->backFrom((int) $service['id'], $result);
    }

    /**
     * Restore overwrites the VPS disk and InterServer re-checks the MyAdmin
     * account password on every call — a credential WHMP does not hold. So
     * this opens a support ticket naming the chosen snapshot rather than
     * firing a destructive call that would be rejected, and a human
     * confirms before any disk is overwritten.
     */
    public function restore(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $backupRef = trim((string) $request->input('backup', ''));

        if ($backupRef === '') {
            return $this->back((int) $service['id'], null, 'Choose which snapshot to restore from.');
        }

        return $this->openRequestTicket(
            $request,
            $service,
            $client,
            'vps.restore_requested',
            "Restore request for service #{$service['id']} ({$service['product_name']})",
            "The client has requested a restore of service #{$service['id']} ({$service['product_name']}).\n\n"
                . "Snapshot: {$backupRef}\n"
                . 'Hostname: ' . ($service['domain'] ?: $service['hostname'] ?: 'not set') . "\n\n"
                . 'This overwrites the VPS disk and requires the MyAdmin account password, so it needs an operator to run it.',
            'Restore requested. Our team will confirm before anything is overwritten.'
        );
    }

    /**
     * Reinstall wipes the disk with no rollback and, like restore, needs the
     * MyAdmin password InterServer re-checks per call. Routed to a ticket
     * for the same reason.
     */
    public function reinstall(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $template = trim((string) $request->input('template', ''));

        if ($template === '') {
            return $this->back((int) $service['id'], null, 'Choose an operating system to reinstall.');
        }

        return $this->openRequestTicket(
            $request,
            $service,
            $client,
            'vps.reinstall_requested',
            "OS reinstall request for service #{$service['id']} ({$service['product_name']})",
            "The client has requested an OS reinstall of service #{$service['id']} ({$service['product_name']}).\n\n"
                . "Requested template: {$template}\n"
                . 'Hostname: ' . ($service['domain'] ?: $service['hostname'] ?: 'not set') . "\n\n"
                . 'This destroys all data on the server with no rollback and requires the MyAdmin account password, so it needs an operator to run it.',
            'OS reinstall requested. Our team will confirm with you before any data is destroyed.'
        );
    }

    /**
     * Reverse DNS. A VPS goes straight to the API, keyed by the specific IP
     * being changed. Dedicated hardware has no rDNS endpoint in Nocix's
     * published API at all, so for those the form opens a support ticket —
     * a real request an operator fulfils, rather than a button that appears
     * to work and quietly does nothing.
     */
    public function rdns(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        $rdns = trim((string) $request->input('rdns', ''));
        $ip = trim((string) $request->input('ip', ''));

        if ($rdns === '') {
            return $this->back((int) $service['id'], null, 'Enter the hostname the IP should resolve to.');
        }

        if ($this->kindOf($service) === self::KIND_DEDICATED) {
            return $this->openRequestTicket(
                $request,
                $service,
                $client,
                'server.rdns_requested',
                "Reverse DNS (PTR) request for service #{$service['id']} ({$service['product_name']})",
                "The client has requested a reverse DNS change on service #{$service['id']} ({$service['product_name']}).\n\n"
                    . 'IP address: ' . ($ip !== '' ? $ip : 'not specified') . "\n"
                    . "Requested PTR hostname: {$rdns}\n\n"
                    . 'Nocix publishes no reverse DNS endpoint, so this needs to be set manually.',
                'Reverse DNS request submitted. Our team will update the PTR record and reply on your ticket.'
            );
        }

        if ($ip === '') {
            return $this->back((int) $service['id'], null, 'Choose which IP address to set reverse DNS for.');
        }

        $result = $this->provisioning->setReverseDns((int) $service['id'], $rdns, $ip);

        $this->activity->log(
            'client',
            (int) $client['id'],
            'server.rdns_updated',
            'service',
            (int) $service['id'],
            sprintf('Client set PTR for %s to %s on service #%d — %s: %s', $ip, $rdns, (int) $service['id'], $result['success'] ? 'accepted' : 'rejected', $result['message']),
            $request->ip()
        );

        return $this->backFrom((int) $service['id'], $result);
    }

    /**
     * Domain Name Changer addon: renames the primary domain on a shared
     * cPanel service via the real WHM API (modifyacct), no ticket needed.
     * Gated on the addon being active — deactivating it hides the button
     * (see renderService()) and this still refuses the POST directly, since
     * a stale bookmarked form should not bypass the toggle.
     */
    public function changeDomain(Request $request, array $params): Response
    {
        [$service, $client, $denied] = $this->ownedService($params);

        if ($denied !== null) {
            return $denied;
        }

        if (!$this->addons->isActive(self::DOMAIN_CHANGER_SLUG)) {
            return $this->back((int) $service['id'], null, 'The domain changer is not currently available.');
        }

        if ($this->kindOf($service) !== self::KIND_SHARED || !$this->isOnCpanelServer($service)) {
            return $this->back((int) $service['id'], null, 'Changing the primary domain is only available for shared cPanel hosting.');
        }

        if ($service['status'] !== 'active') {
            return $this->back((int) $service['id'], null, 'This service must be active to change its domain.');
        }

        $newDomain = strtolower(trim((string) $request->input('new_domain', '')));

        if ($newDomain === '') {
            return $this->back((int) $service['id'], null, 'Enter the domain to switch this hosting account to.');
        }

        if (preg_match(self::DOMAIN_PATTERN, $newDomain) !== 1) {
            return $this->back((int) $service['id'], null, "\"{$newDomain}\" doesn't look like a valid domain name.");
        }

        if ($newDomain === strtolower((string) ($service['domain'] ?? ''))) {
            return $this->back((int) $service['id'], null, 'That is already this service\'s domain.');
        }

        $oldDomain = (string) ($service['domain'] ?? 'not set');
        $result = $this->provisioning->changeDomain((int) $service['id'], $newDomain);

        $this->activity->log(
            'client',
            (int) $client['id'],
            'service.domain_changed',
            'service',
            (int) $service['id'],
            sprintf(
                'Client changed the primary domain on service #%d from "%s" to "%s" — %s: %s',
                (int) $service['id'],
                $oldDomain,
                $newDomain,
                $result['success'] ? 'succeeded' : 'failed',
                $result['message']
            ),
            $request->ip()
        );

        if (!$result['success']) {
            return $this->back((int) $service['id'], null, $result['message']);
        }

        return $this->back((int) $service['id'], "Primary domain changed to {$newDomain}.");
    }

    /**
     * Opens a support ticket for an action the vendor API cannot perform
     * unattended, logs it, and returns the client to the service page.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $client
     */
    private function openRequestTicket(
        Request $request,
        array $service,
        array $client,
        string $activityAction,
        string $subject,
        string $body,
        string $confirmation
    ): Response {
        $departments = $this->departments->all();

        if ($departments === []) {
            return $this->back((int) $service['id'], null, 'No support department is configured — please contact us directly.');
        }

        $ticketId = $this->tickets->open(
            (int) $client['id'],
            (string) $client['email'],
            (int) $departments[0]['id'],
            $subject,
            trim((string) $client['first_name'] . ' ' . (string) $client['last_name']),
            $body
        );

        $this->activity->log(
            'client',
            (int) $client['id'],
            $activityAction,
            'service',
            (int) $service['id'],
            "Opened ticket #{$ticketId} for service #{$service['id']}: {$subject}",
            $request->ip()
        );

        return $this->back((int) $service['id'], $confirmation . " (Ticket #{$ticketId})");
    }

    /**
     * Which management surface this service gets. Keyed off the assigned
     * server's module first — that is a fact about what actually runs the
     * service — and only falls back to product-name matching when a service
     * has no server attached. The name-only heuristic this replaced would
     * classify a shared plan called "Cloud Hosting" as a VPS and show it
     * power controls it has no way to honour.
     *
     * @param array<string, mixed> $service
     */
    private function kindOf(array $service): string
    {
        $slug = strtolower((string) $this->moduleSlugFor($service));

        if ($slug !== '') {
            if (str_contains($slug, 'dedicated') || str_contains($slug, 'nocix')) {
                return self::KIND_DEDICATED;
            }

            if (str_contains($slug, 'vps')) {
                return self::KIND_VPS;
            }

            if (str_contains($slug, 'cpanel') || str_contains($slug, 'panel')) {
                return self::KIND_SHARED;
            }
        }

        $name = strtolower((string) ($service['product_name'] ?? ''));

        if (str_contains($name, 'dedicated') || str_starts_with($name, 'ds-') || str_starts_with($name, 'ids-')) {
            return self::KIND_DEDICATED;
        }

        if (str_contains($name, 'vps') || str_contains($name, 'virtual server')) {
            return self::KIND_VPS;
        }

        return self::KIND_SHARED;
    }

    /** @param array<string, mixed> $service */
    private function moduleSlugFor(array $service): ?string
    {
        if (($service['server_id'] ?? null) === null) {
            return null;
        }

        $server = $this->servers->find((int) $service['server_id']);

        return $server === null ? null : (string) ($server['module_slug'] ?? '');
    }

    /**
     * Assembles the page. Remote reads are attempted only for an active
     * service on a module that supports them, and a failure degrades to "not
     * reported" in the view rather than a fabricated default.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $client
     */
    private function renderService(array $service, array $client, ?string $message, ?string $error): Response
    {
        $id = (int) $service['id'];
        $kind = $this->kindOf($service);
        $isActive = $service['status'] === 'active';
        $currency = $this->currency->resolveForClient($client);

        $usage = $isActive ? $this->provisioning->usage($id) : null;
        $remote = [];
        $reverseDns = [];
        $backups = [];
        $osTemplates = [];
        $slices = [];

        if ($isActive && $kind === self::KIND_VPS) {
            $info = $this->provisioning->remoteInfo($id);
            $remote = $info['success'] ? ($info['info'] ?? []) : [];

            $ptr = $this->provisioning->reverseDnsEntries($id);
            $reverseDns = $ptr['success'] ? ($ptr['ips'] ?? []) : [];

            $backupList = $this->provisioning->listBackups($id);
            $backups = $backupList['success'] ? ($backupList['backups'] ?? []) : [];

            $templates = $this->provisioning->osTemplates($id);
            $osTemplates = $templates['success'] ? ($templates['templates'] ?? []) : [];

            $sliceInfo = $this->provisioning->sliceOptions($id);
            $slices = $sliceInfo['success'] ? ($sliceInfo['slices'] ?? []) : [];
        }

        return $this->page('billing.client-service-show', [
            'service' => $service,
            'kind' => $kind,
            'usage' => $usage,
            'remote' => $remote,
            'reverseDns' => $reverseDns,
            'backups' => $backups,
            'osTemplates' => $osTemplates,
            'slices' => $slices,
            'cpanelToolsAvailable' => $this->isOnCpanelServer($service),
            'domainChangerAvailable' => $this->isOnCpanelServer($service) && $this->addons->isActive(self::DOMAIN_CHANGER_SLUG),
            'currency' => $currency,
            // services.amount is written once at checkout via
            // CheckoutService::buildOrder(), which locks currency through
            // denominateFor() — the figure is already in the client's own
            // currency, not base currency, and has no per-row exchange rate
            // (services carries no currency_id/currency_rate columns at all).
            // Passing it through format()/rateFor() here re-multiplies an
            // already-denominated amount by today's live rate: the same
            // service that reads correctly on its own invoice as ₦41,397.17
            // rendered here as ₦62,095,750.00. Show it raw, matching every
            // other services.amount display in the app (admin service list,
            // admin service page, client detail page) — see the identical
            // note on the client dashboard's services widget.
            'formattedAmount' => ($currency['symbol'] ?? '$') . number_format((float) ($service['amount'] ?? 0), 2),
            'pendingCancellation' => $this->cancellations->findPendingForService($id),
            'nameservers' => $this->domainSettings->defaultNameservers(),
            'message' => $message,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: Response|null}
     */
    private function ownedService(array $params): array
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return [null, null, Response::redirect('/client/login')];
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return [null, null, Response::html('404 Not Found', 404)];
        }

        return [$service, $client, null];
    }

    /** @param array<string, mixed> $result */
    private function backFrom(int $serviceId, array $result): Response
    {
        return $result['success']
            ? $this->back($serviceId, (string) $result['message'])
            : $this->back($serviceId, null, (string) $result['message']);
    }

    private function back(int $serviceId, ?string $message = null, ?string $error = null): Response
    {
        $query = $message !== null
            ? '?msg=' . urlencode($message)
            : ($error !== null ? '?err=' . urlencode($error) : '');

        return Response::redirect("/client/services/{$serviceId}{$query}");
    }

    /** @param array<string, mixed> $service */
    private function isOnCpanelServer(array $service): bool
    {
        return (string) $this->moduleSlugFor($service) === 'cpanel';
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Services',
            'content' => $content,
        ]));
    }
}
