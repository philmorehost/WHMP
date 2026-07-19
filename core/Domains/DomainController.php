<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
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
        private readonly DomainPricingRepository $domainPricing
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');

        return $this->render('domains.index', [
            'domains' => $this->domains->all($status !== '' ? $status : null),
            'statusFilter' => $status,
        ]);
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
                $amount = (float) $tldPricing['register_price'];
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

        return $this->render('domains.show', ['domain' => $domain]);
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

        return Response::redirect("/admin/domains/{$id}/contact");
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
