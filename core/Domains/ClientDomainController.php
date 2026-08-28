<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * My Domains (blueprint §4.1): nameservers, lock, EPP, WHOIS/ID
 * protection, DNS management. Email forwarding is deferred — none of the
 * three registrar modules expose it through a method on the
 * RegistrarModule interface today (Upperlink's API has the endpoints;
 * adding it is additive once there's a real need, not a redesign).
 */
final class ClientDomainController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly DomainRepository $domains,
        private readonly DomainService $domainService,
        private readonly ClientContactRepository $contacts
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('domains.client-index', [
            'domains' => $this->domains->forClient((int) $client['id']),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $id = (int) $domain['id'];
        $client = $this->guard->currentClient();

        return $this->page('domains.client-show', [
            'domain' => $domain,
            'eppCode' => null,
            'childNameservers' => $this->domainService->getChildNameservers($id),
            'dnsRecords' => $this->domainService->getDnsRecords($id),
            // The registrant contact shown here is the LOCAL copy (no registrar
            // round-trip on page load); it's pushed to the registrar on save.
            'clientContacts' => $this->contacts->forClient((int) $client['id']),
            'registrantContact' => json_decode((string) ($domain['contact_data'] ?? '[]'), true) ?: [],
            'registrantContactId' => (int) ($domain['contact_id'] ?? 0),
            'msg' => $request->query('msg'),
            'error' => $request->query('error'),
        ]);
    }

    /**
     * WHMCS-style per-domain registrant contact: pick a saved client contact
     * (registered "on behalf of" someone/org) or enter a custom one. The
     * choice is persisted on the domain and pushed to the registrar when
     * possible.
     */
    public function saveContact(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $client = $this->guard->currentClient();
        $id = (int) $domain['id'];
        $clientId = (int) $client['id'];

        // A single registrant-source select drives this: '' = custom contact
        // (the fields below), '-1' = the client's own account details, N = one
        // of the client's saved contacts (registered "on behalf of").
        $rawContactId = (string) $request->input('contact_id', '');
        $contactId = null;

        if ($rawContactId === '-1') {
            // Registrant = the client's own account details.
            $contact = $this->domainService->contactFromClient($client);
        } elseif ($rawContactId !== '' && (int) $rawContactId > 0) {
            // Registrant = one of the client's saved contacts.
            $contactId = (int) $rawContactId;
            $saved = $this->contacts->find($contactId);
            if ($saved === null || (int) $saved['client_id'] !== $clientId) {
                return Response::redirect("/client/domains/{$id}?error=" . urlencode('That saved contact could not be found.'));
            }
            $contact = $this->domainService->contactFromClientContact($saved);
        } else {
            // Registrant = a custom contact entered here ("on behalf of").
            $contact = $this->contactFromRequest($request);

            // Optionally keep it as a reusable saved contact.
            if ((bool) $request->input('save_to_contacts', 0)) {
                $contactId = $this->contacts->create($clientId, (string) ($contact['name'] ?? ''), (string) ($contact['email'] ?? ''), [], $contact);
            }
        }

        $result = $this->domainService->saveContactInfo($id, $contact, $contactId);

        if ($result['success']) {
            return Response::redirect("/client/domains/{$id}?msg=" . urlencode((string) ($result['message'] ?? 'Registrant contact saved.')));
        }

        return Response::redirect("/client/domains/{$id}?error=" . urlencode((string) $result['message']));
    }

    /** @return array<string, string> */
    private function contactFromRequest(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name', '')),
            'email' => trim((string) $request->input('email', '')),
            'company_name' => trim((string) $request->input('company_name', '')),
            'address1' => trim((string) $request->input('address1', '')),
            'city' => trim((string) $request->input('city', '')),
            'state' => trim((string) $request->input('state', '')),
            'postcode' => trim((string) $request->input('postcode', '')),
            'country' => trim((string) $request->input('country', '')),
            'phone' => trim((string) $request->input('phone', '')),
        ];
    }

    public function toggleLock(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $result = $this->domainService->setLock((int) $domain['id'], !$domain['registrar_lock_enabled']);

        if (!$result['success']) {
            return Response::redirect("/client/domains/{$domain['id']}?error=" . urlencode($result['message']));
        }

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function toggleIdProtection(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $result = $this->domainService->setIdProtection((int) $domain['id'], !$domain['id_protection_enabled']);

        if (!$result['success']) {
            return Response::redirect("/client/domains/{$domain['id']}?error=" . urlencode($result['message']));
        }

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function saveNameservers(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $ns = array_values(array_filter([
            trim((string) $request->input('ns1', '')),
            trim((string) $request->input('ns2', '')),
            trim((string) $request->input('ns3', '')),
            trim((string) $request->input('ns4', '')),
            trim((string) $request->input('ns5', '')),
            trim((string) $request->input('ns6', '')),
        ]));

        $result = $this->domainService->saveNameservers((int) $domain['id'], $ns);

        if (!$result['success']) {
            return Response::redirect("/client/domains/{$domain['id']}?error=" . urlencode($result['message']));
        }

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function addChildNameserver(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $hostname = trim((string) $request->input('hostname', ''));
        $ip = trim((string) $request->input('ip_address', ''));

        $result = $this->domainService->addChildNameserver((int) $domain['id'], $hostname, $ip);

        if (!$result['success']) {
            return Response::redirect("/client/domains/{$domain['id']}?error=" . urlencode($result['message']));
        }

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function deleteChildNameserver(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $childNsId = (int) $params['child_id'];
        $result = $this->domainService->deleteChildNameserver((int) $domain['id'], $childNsId);

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function addDnsRecord(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $type = (string) $request->input('type', 'A');
        $name = (string) $request->input('name', '@');
        $content = (string) $request->input('content', '');
        $priority = (int) $request->input('priority', 10);
        $ttl = (int) $request->input('ttl', 3600);

        $result = $this->domainService->addDnsRecord((int) $domain['id'], $type, $name, $content, $priority, $ttl);

        if (!$result['success']) {
            return Response::redirect("/client/domains/{$domain['id']}?error=" . urlencode($result['message']));
        }

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function deleteDnsRecord(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $recordId = (int) $params['record_id'];
        $result = $this->domainService->deleteDnsRecord((int) $domain['id'], $recordId);

        return Response::redirect("/client/domains/{$domain['id']}?msg=" . urlencode($result['message']));
    }

    public function eppCode(Request $request, array $params): Response
    {
        $domain = $this->ownedDomain($params);

        if ($domain === null) {
            return $this->deniedOrNotFound();
        }

        $id = (int) $domain['id'];
        $result = $this->domainService->getEppCode($id);

        return $this->page('domains.client-show', [
            'domain' => $this->domains->find($id) ?: $domain,
            'eppCode' => $result['success'] ? $result['eppCode'] : null,
            'eppError' => $result['success'] ? null : $result['message'],
            'childNameservers' => $this->domainService->getChildNameservers($id),
            'dnsRecords' => $this->domainService->getDnsRecords($id),
            'msg' => $result['success'] ? $result['message'] : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function ownedDomain(array $params): ?array
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return null;
        }

        $domain = $this->domains->find((int) $params['id']);

        if ($domain === null || (int) $domain['client_id'] !== (int) $client['id']) {
            return null;
        }

        return $domain;
    }

    private function deniedOrNotFound(): Response
    {
        return $this->guard->check() ? Response::html('404 Not Found', 404) : Response::redirect('/client/login');
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Domains',
            'content' => $content,
        ]));
    }
}
