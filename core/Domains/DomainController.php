<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

final class DomainController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DomainRepository $domains,
        private readonly ClientRepository $clients,
        private readonly RegistrarRepository $registrars,
        private readonly DomainService $domainService,
        private readonly ActivityLogger $activity,
        private readonly DomainPricingRepository $domainPricing,
        private readonly DomainSettings $domainSettings,
        private readonly WhoisService $whoisService,
        private readonly CurrencyService $currency
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = max(1, (int) $request->query('page', 1));
        $whoisDomain = trim((string) $request->query('domain', ''));
        $whoisSearchResult = null;
        if ($whoisDomain !== '') {
            $whoisSearchResult = $this->whoisService->lookup($whoisDomain);
        }

        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['domain' => true, 'client' => true, 'tld' => true, 'registrar' => true, 'expiry' => true, 'status' => true]
        );

        $sort = \CodeVault\Table\TableFilters::sortFromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['domain' => 'd.domain_name', 'client' => 'c.last_name', 'registrar' => 'd.registrar_slug', 'expiry' => 'd.expiry_date', 'status' => 'd.status']
        );

        $results = $this->domains->paginate($status !== '' ? $status : null, $page, 15, $filters, $sort);

        return $this->render('domains.index', [
            'results' => $results,
            'domains' => $results['data'],
            'statusFilter' => $status,
            'filters' => $filters,
            'sort' => $sort,
            'filterColumns' => [
                ['filterable' => false],
                ['filterable' => true, 'key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'placeholder' => 'e.g. example.com.ng'],
                ['filterable' => true, 'key' => 'client', 'label' => 'Client', 'type' => 'text', 'placeholder' => 'Name or email'],
                ['filterable' => true, 'key' => 'registrar', 'label' => 'Registrar', 'type' => 'text', 'placeholder' => 'Registrar slug'],
                ['filterable' => true, 'key' => 'expiry', 'label' => 'Expiry', 'type' => 'text', 'placeholder' => 'YYYY-MM-DD'],
                ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    'active' => 'Active',
                    'pending' => 'Pending',
                    'expired' => 'Expired',
                    'cancelled' => 'Cancelled',
                    'transferred' => 'Transferred',
                ]],
                ['filterable' => false],
            ],
            'statusCounts' => $this->domains->countByStatus(),
            'registrarCounts' => $this->domains->countActiveByRegistrar(),
            'registrars' => $this->registrars->allEnabled(),
            'defaultNameservers' => $this->domainSettings->defaultNameservers(),
            'autoDeleteExpiredEnabled' => $this->domainSettings->autoDeleteExpiredEnabled(),
            'deletionGraceDays' => $this->domainSettings->deletionGraceDays(),
            'whoisDomain' => $whoisDomain,
            'whoisSearchResult' => $whoisSearchResult,
        ]);
    }

    public function updateDefaultNameservers(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->domainSettings->setDefaultNameservers((array) $request->input('ns', []));

        return Response::redirect('/admin/domains');
    }

    public function updateDeletionPolicy(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->domainSettings->saveDeletionPolicy(
            (bool) $request->input('auto_delete_expired_enabled'),
            (int) $request->input('deletion_grace_days', 30)
        );

        return Response::redirect('/admin/domains');
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('domains.create', [
            'clients' => $this->clients->all(),
            'registrars' => $this->registrars->allEnabled(),
            'error' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $domainName = strtolower(trim((string) $request->input('domain_name', '')));
        $clientId = (int) $request->input('client_id', 0);
        $registrarSlug = (string) $request->input('registrar_slug', '');
        $years = max(1, (int) $request->input('years', 1));
        $amount = (float) $request->input('amount', 0);

        if ($amount === 0.0 && $domainName !== '') {
            $tld = '.' . ltrim(substr($domainName, (int) strpos($domainName, '.') + 1), '.');
            $tldPricing = $this->domainPricing->findByTld($tld);
            if ($tldPricing !== null) {
                // A fresh, never-before-charged catalog read — convert once,
                // for the target client, same as CheckoutService does at
                // checkout. An admin-TYPED amount (the branch this skips) is
                // left alone, matching AdminInvoiceController's convention
                // that a human-entered figure is already final.
                $targetClient = $clientId > 0 ? $this->clients->find($clientId) : null;
                $amount = $this->currency->convert(
                    (float) $tldPricing['register_price'],
                    $this->currency->rateFor($this->currency->resolveForClient($targetClient))
                );
            }
        }

        if ($domainName === '' || $clientId === 0 || $registrarSlug === '') {
            return $this->render('domains.create', [
                'clients' => $this->clients->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => 'Domain name, client, and registrar are required.',
            ]);
        }

        if ($this->domains->findByName($domainName) !== null) {
            return $this->render('domains.create', [
                'clients' => $this->clients->all(),
                'registrars' => $this->registrars->allEnabled(),
                'error' => "\"{$domainName}\" is already in the system.",
            ]);
        }

        $nextDue = (new DateTimeImmutable("+{$years} years"))->format('Y-m-d');

        $domainId = $this->domains->create([
            'client_id' => $clientId,
            'domain_name' => $domainName,
            'registrar_slug' => $registrarSlug,
            'status' => 'pending',
            'next_due_date' => $nextDue,
            'amount' => $amount,
        ]);

        $result = $this->domainService->register($domainId, $years);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'domain.register_attempted',
            'domain',
            $domainId,
            $result['success'] ? "Registered domain \"{$domainName}\"" : "Registration failed for \"{$domainName}\": {$result['message']}",
            $request->ip()
        );

        return Response::redirect("/admin/domains/{$domainId}");
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $domain = $this->domains->find((int) $params['id']);

        if ($domain === null) {
            return Response::html('404 Not Found', 404);
        }

        $id = (int) $domain['id'];
        $whoisResult = null;
        if ($request->query('whois') === '1') {
            $whoisResult = $this->whoisService->lookup((string) $domain['domain_name']);
        }

        return $this->render('domains.show', [
            'domain' => $domain,
            'registrars' => $this->registrars->allEnabled(),
            'updated' => $request->query('updated') === '1',
            'registrarError' => $request->query('registrar_error'),
            'registered' => $request->query('registered') === '1',
            'registerError' => $request->query('register_error'),
            'childNameservers' => $this->domainService->getChildNameservers($id),
            'dnsRecords' => $this->domainService->getDnsRecords($id),
            'bulkMsg' => $request->query('bulk_msg'),
            'whoisResult' => $whoisResult,
        ]);
    }

    public function whois(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        return Response::redirect("/admin/domains/{$id}?whois=1#whois-record");
    }

    public function bulkSync(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $registrarSlug = trim((string) $request->input('registrar_slug', ''));
        $result = $this->domainService->bulkSync($registrarSlug !== '' ? $registrarSlug : null);

        $msg = "Bulk Sync completed: {$result['success']} succeeded out of {$result['total']} domains ({$result['failed']} failed).";
        return Response::redirect('/admin/domains?bulk_msg=' . urlencode($msg));
    }

    public function bulkRefreshNameservers(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $registrarSlug = trim((string) $request->input('registrar_slug', ''));
        $result = $this->domainService->bulkRefreshNameservers($registrarSlug !== '' ? $registrarSlug : null);

        $msg = "Bulk Nameserver Refresh completed: {$result['success']} domains updated out of {$result['total']} ({$result['failed']} failed or returned empty).";
        return Response::redirect('/admin/domains?bulk_msg=' . urlencode($msg));
    }

    public function bulkDelete(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('domain_ids', []));
        if (empty($ids)) {
            return Response::redirect('/admin/domains?bulk_msg=' . urlencode('No domains were selected for deletion.'));
        }

        $deletedCount = $this->domainService->bulkDelete($ids);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'domains.bulk_delete', 'domain', null, "Bulk deleted {$deletedCount} domains.");

        $msg = "Successfully deleted {$deletedCount} domain(s).";
        return Response::redirect('/admin/domains?bulk_msg=' . urlencode($msg));
    }

    public function bulkUpdateStatus(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('domain_ids', []));
        $status = trim((string) $request->input('status', ''));

        if (empty($ids)) {
            return Response::redirect('/admin/domains?bulk_msg=' . urlencode('No domains were selected for status update.'));
        }

        if ($status === '') {
            return Response::redirect('/admin/domains?bulk_msg=' . urlencode('Please select a valid status to apply.'));
        }

        $updatedCount = $this->domainService->bulkUpdateStatus($ids, $status);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'domains.bulk_status', 'domain', null, "Bulk updated status to {$status} for {$updatedCount} domains.");

        $msg = "Successfully updated status for {$updatedCount} domain(s) to " . ucfirst($status) . ".";
        return Response::redirect('/admin/domains?bulk_msg=' . urlencode($msg));
    }

    public function bulkUpdateRegistrar(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('domain_ids', []));
        $slug = trim((string) $request->input('registrar_slug', ''));

        if (empty($ids)) {
            return Response::redirect('/admin/domains?bulk_msg=' . urlencode('No domains were selected for registrar update.'));
        }

        if ($slug === '' || $this->registrars->findBySlug($slug) === null) {
            return Response::redirect('/admin/domains?bulk_msg=' . urlencode('Please select a valid registrar to apply.'));
        }

        $updatedCount = $this->domainService->bulkUpdateRegistrar($ids, $slug);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'domains.bulk_registrar', 'domain', null, "Bulk updated registrar to {$slug} for {$updatedCount} domains.");

        $msg = "Successfully updated registrar to {$slug} for {$updatedCount} domain(s).";
        return Response::redirect('/admin/domains?bulk_msg=' . urlencode($msg));
    }

    public function addChildNameserver(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $hostname = trim((string) $request->input('hostname', ''));
        $ip = trim((string) $request->input('ip_address', ''));

        $this->domainService->addChildNameserver($id, $hostname, $ip);
        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    public function deleteChildNameserver(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $childNsId = (int) $params['child_id'];

        $this->domainService->deleteChildNameserver($id, $childNsId);
        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    public function addDnsRecord(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $type = (string) $request->input('type', 'A');
        $name = (string) $request->input('name', '@');
        $content = (string) $request->input('content', '');
        $priority = (int) $request->input('priority', 10);
        $ttl = (int) $request->input('ttl', 3600);

        $this->domainService->addDnsRecord($id, $type, $name, $content, $priority, $ttl);
        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    public function deleteDnsRecord(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $recordId = (int) $params['record_id'];

        $this->domainService->deleteDnsRecord($id, $recordId);
        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    public function updateStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $domain = $this->domains->find($id);

        if ($domain === null) {
            return Response::html('404 Not Found', 404);
        }

        $status = (string) $request->input('status', 'active');
        $registrationDate = trim((string) $request->input('registration_date', ''));
        $expiryDate = trim((string) $request->input('expiry_date', ''));
        $nextDueDate = trim((string) $request->input('next_due_date', ''));
        $autoRenew = $request->input('auto_renew') ? 1 : 0;
        $amount = (float) $request->input('amount', 0);

        // "Set to TLD price" backs the domain's amount onto the current
        // catalog renewal price for its TLD — the admin equivalent of pulling
        // a migrated-in domain onto the current price list. Converted once,
        // for the owning client, same as CheckoutService does at checkout.
        if ($request->input('set_tld_price') !== null) {
            $tld = '.' . ltrim(substr((string) $domain['domain_name'], (int) strpos((string) $domain['domain_name'], '.') + 1), '.');
            $tldPricing = $this->domainPricing->findByTld($tld);

            if ($tldPricing === null) {
                return Response::redirect("/admin/domains/{$id}?price_error=" . urlencode("No pricing row exists for the TLD {$tld}."));
            }

            $amount = $this->currency->convert(
                (float) $tldPricing['renew_price'],
                $this->currency->rateFor($this->currency->resolveForClient($this->clients->find((int) $domain['client_id'])))
            );
        }

        $this->domains->updateStatusAndDates($id, [
            'status' => $status,
            'registration_date' => $registrationDate !== '' ? $registrationDate : null,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            'next_due_date' => $nextDueDate !== '' ? $nextDueDate : null,
            'auto_renew' => $autoRenew,
            'amount' => $amount,
        ]);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'domain.status_updated',
            'domain',
            $id,
            "Updated domain status for \"{$domain['domain_name']}\" to {$status}" . ($request->input('set_tld_price') !== null ? ' (amount set to TLD price)' : ''),
            $request->ip()
        );

        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    public function updateRegistrar(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $domain = $this->domains->find($id);

        if ($domain === null) {
            return Response::html('404 Not Found', 404);
        }

        $slug = trim((string) $request->input('registrar_slug', ''));

        if ($slug === '' || $this->registrars->findBySlug($slug) === null) {
            return Response::redirect("/admin/domains/{$id}?registrar_error=" . urlencode('That registrar does not exist.'));
        }

        if ($slug === (string) $domain['registrar_slug']) {
            return Response::redirect("/admin/domains/{$id}?updated=1");
        }

        $this->domains->updateRegistrar($id, $slug);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'domain.registrar_updated',
            'domain',
            $id,
            "Changed registrar for \"{$domain['domain_name']}\" from {$domain['registrar_slug']} to {$slug}",
            $request->ip()
        );

        return Response::redirect("/admin/domains/{$id}?updated=1");
    }

    /**
     * Recovery path for a domain that was paid for but failed to register at
     * the registrar (its provisioning_error is populated, status is pending).
     * The admin picks the registrar to submit it to and re-runs the
     * registration — the optional registrar_slug re-points the domain first
     * (clearing any stale handles from the previous registrar) so the wrong
     * registrar can be corrected in the same step.
     */
    public function registerDomain(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $domain = $this->domains->find($id);

        if ($domain === null) {
            return Response::html('404 Not Found', 404);
        }

        $slug = trim((string) $request->input('registrar_slug', ''));

        if ($slug !== '' && $this->registrars->findBySlug($slug) === null) {
            return Response::redirect("/admin/domains/{$id}?register_error=" . urlencode('That registrar does not exist.'));
        }

        if ($slug !== '' && $slug !== (string) $domain['registrar_slug']) {
            $this->domains->updateRegistrar($id, $slug);

            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'domain.registrar_updated',
                'domain',
                $id,
                "Changed registrar for \"{$domain['domain_name']}\" from {$domain['registrar_slug']} to {$slug}",
                $request->ip()
            );
        }

        $effectiveSlug = $slug !== '' ? $slug : (string) $domain['registrar_slug'];
        $years = max(1, min(10, (int) $request->input('years', 1)));
        $result = $this->domainService->register($id, $years);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'domain.register_retried',
            'domain',
            $id,
            $result['success']
                ? "Registration re-triggered for \"{$domain['domain_name']}\" via {$effectiveSlug} ({$years}y)."
                : "Registration re-trigger FAILED for \"{$domain['domain_name']}\" via {$effectiveSlug}: {$result['message']}",
            $request->ip()
        );

        return Response::redirect($result['success']
            ? "/admin/domains/{$id}?registered=1"
            : "/admin/domains/{$id}?register_error=" . urlencode((string) ($result['message'] ?? 'Registration failed.')));
    }

    public function renew(Request $request, array $params): Response
    {
        return $this->action($request, $params, fn (int $id) => $this->domainService->renew($id), 'domain.renewed');
    }

    public function sync(Request $request, array $params): Response
    {
        return $this->action($request, $params, fn (int $id) => $this->domainService->sync($id), 'domain.synced');
    }

    public function toggleLock(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $domain = $this->domains->find($id);
        $lock = $domain !== null && !$domain['registrar_lock_enabled'];

        return $this->action($request, $params, fn () => $this->domainService->setLock($id, $lock), 'domain.lock_toggled');
    }

    public function toggleIdProtection(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $domain = $this->domains->find($id);
        $enable = $domain !== null && !$domain['id_protection_enabled'];

        return $this->action($request, $params, fn () => $this->domainService->setIdProtection($id, $enable), 'domain.id_protection_toggled');
    }

    public function refreshNameservers(Request $request, array $params): Response
    {
        return $this->action($request, $params, function (int $id) {
            $result = $this->domainService->getNameservers($id);

            // getNameservers()'s contract (RegistrarModule::getNameservers())
            // doesn't guarantee a 'message' key — the activity log needs one.
            return $result + ['message' => $result['success'] ? 'Nameservers refreshed from registrar.' : 'Registrar did not return nameservers.'];
        }, 'domain.nameservers_refreshed');
    }

    public function saveNameservers(Request $request, array $params): Response
    {
        $ns = array_values(array_filter([
            trim((string) $request->input('ns1', '')),
            trim((string) $request->input('ns2', '')),
            trim((string) $request->input('ns3', '')),
            trim((string) $request->input('ns4', '')),
            trim((string) $request->input('ns5', '')),
            trim((string) $request->input('ns6', '')),
        ]));

        return $this->action($request, $params, fn (int $id) => $this->domainService->saveNameservers($id, $ns), 'domain.nameservers_saved');
    }

    /**
     * Contact info has no local cache column (unlike nameservers) — this
     * page always live-fetches from the registrar on load, so it's its own
     * page rather than a section on the main show() view.
     */
    public function contactShow(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $domain = $this->domains->find($id);

        if ($domain === null) {
            return Response::html('404 Not Found', 404);
        }

        $result = $this->domainService->getContactInfo($id);

        return $this->render('domains.contact', [
            'domain' => $domain,
            'contact' => $result['contacts'] ?? [],
            'fetchError' => $result['success'] ? null : ($result['message'] ?? null),
            // 'registrar' when the contact came back from the registrar API,
            // 'local' when it fell back to the locally stored / client copy
            // (with $notice carrying why).
            'contactSource' => (string) ($result['source'] ?? 'registrar'),
            'notice' => $result['notice'] ?? null,
            'msg' => (string) $request->query('msg', ''),
            'saveError' => (string) $request->query('saved_error', ''),
        ]);
    }

    public function saveContact(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $contact = [
            'name' => trim((string) $request->input('name', '')),
            'email' => trim((string) $request->input('email', '')),
            'company_name' => trim((string) $request->input('company_name', '')),
            'address1' => trim((string) $request->input('address1', '')),
            'city' => trim((string) $request->input('city', '')),
            'state' => trim((string) $request->input('state', '')),
            'country' => trim((string) $request->input('country', '')),
            'postcode' => trim((string) $request->input('postcode', '')),
            'phone' => trim((string) $request->input('phone', '')),
        ];

        $result = $this->domainService->saveContactInfo($id, $contact);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'domain.contact_saved',
            'domain',
            $id,
            $result['success'] ? "Contact info saved for domain #{$id}" : "Contact info save FAILED for domain #{$id}: {$result['message']}",
            $request->ip()
        );

        if ($result['success']) {
            return Response::redirect("/admin/domains/{$id}/contact?msg=" . urlencode((string) ($result['message'] ?? 'Contact info saved.')));
        }

        // The edit was kept locally but the registrar rejected the push — the
        // contact page must show exactly why.
        return Response::redirect("/admin/domains/{$id}/contact?saved_error=" . urlencode((string) ($result['message'] ?? 'Could not save contact info to the registrar.')));
    }

    private function action(Request $request, array $params, callable $action, string $logAction): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $result = $action($id);
        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            $logAction,
            'domain',
            $id,
            $result['success'] ? "{$logAction} for domain #{$id}" : "{$logAction} FAILED for domain #{$id}: {$result['message']}",
            $request->ip()
        );

        return Response::redirect("/admin/domains/{$id}");
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::DOMAINS_MANAGE)) {
            return Response::html('403 Forbidden — missing domains.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Domains',
            'content' => $content,
        ]));
    }
}
