<?php

declare(strict_types=1);

namespace CodeVault\CpanelTools;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Client-area cPanel Extended tools page (blueprint parity with the WHMCS
 * "cPanel Extended" addon's client-area feature set, first slice: email
 * accounts, FTP accounts, MySQL databases, DNS zone editor, quick logins).
 * One query-string-tabbed page per service, mirroring the admin client
 * profile's `?tab=` pattern (resources/views/clients/show.php).
 */
final class CpanelToolsController
{
    private const TABS = ['email', 'ftp', 'databases', 'dns', 'domains', 'cron', 'ssh', 'ssl', 'usage', 'logins'];

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly CpanelToolsService $tools
    ) {
    }

    public function index(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        return $this->render($id, $service, (string) $request->query('tab', 'email'), null);
    }

    public function storeEmail(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createEmailAccount(
            $id,
            trim((string) $request->input('local_part', '')),
            (string) $request->input('password', ''),
            (int) $request->input('quota_mb', 250)
        );

        return $this->render($id, $service, 'email', $result);
    }

    public function destroyEmail(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteEmailAccount($id, (string) $request->input('local_part', ''));

        return $this->render($id, $service, 'email', $result);
    }

    public function storeForwarder(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createForwarder(
            $id,
            trim((string) $request->input('local_part', '')),
            trim((string) $request->input('destination', ''))
        );

        return $this->render($id, $service, 'email', $result);
    }

    public function destroyForwarder(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteForwarder(
            $id,
            (string) $request->input('address', ''),
            (string) $request->input('forwarder', '')
        );

        return $this->render($id, $service, 'email', $result);
    }

    public function storeAutoresponder(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createAutoresponder(
            $id,
            trim((string) $request->input('local_part', '')),
            trim((string) $request->input('subject', '')),
            (string) $request->input('body', '')
        );

        return $this->render($id, $service, 'email', $result);
    }

    public function destroyAutoresponder(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteAutoresponder($id, (string) $request->input('local_part', ''));

        return $this->render($id, $service, 'email', $result);
    }

    public function storeFtp(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createFtpAccount(
            $id,
            trim((string) $request->input('username', '')),
            (string) $request->input('password', ''),
            trim((string) $request->input('homedir', 'public_html')),
            (int) $request->input('quota_mb', 0)
        );

        return $this->render($id, $service, 'ftp', $result);
    }

    public function destroyFtp(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteFtpAccount($id, (string) $request->input('username', ''));

        return $this->render($id, $service, 'ftp', $result);
    }

    public function storeDatabase(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createDatabase($id, trim((string) $request->input('name', '')));

        return $this->render($id, $service, 'databases', $result);
    }

    public function destroyDatabase(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteDatabase($id, (string) $request->input('name', ''));

        return $this->render($id, $service, 'databases', $result);
    }

    public function storeDns(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->addDnsRecord(
            $id,
            trim((string) $request->input('name', '')),
            (string) $request->input('type', 'A'),
            trim((string) $request->input('value', '')),
            (int) $request->input('ttl', 14400)
        );

        return $this->render($id, $service, 'dns', $result);
    }

    public function destroyDns(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteDnsRecord($id, (int) $request->input('line', 0));

        return $this->render($id, $service, 'dns', $result);
    }

    public function storeAddonDomain(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createAddonDomain(
            $id,
            trim((string) $request->input('new_domain', '')),
            trim((string) $request->input('subdomain', '')),
            trim((string) $request->input('dir', ''))
        );

        return $this->render($id, $service, 'domains', $result);
    }

    public function destroyAddonDomain(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteAddonDomain(
            $id,
            (string) $request->input('domain', ''),
            (string) $request->input('subdomain', '')
        );

        return $this->render($id, $service, 'domains', $result);
    }

    public function storeSubdomain(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createSubdomain(
            $id,
            trim((string) $request->input('subdomain', '')),
            trim((string) $request->input('root_domain', '')),
            trim((string) $request->input('dir', ''))
        );

        return $this->render($id, $service, 'domains', $result);
    }

    public function destroySubdomain(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteSubdomain($id, (string) $request->input('domain', ''));

        return $this->render($id, $service, 'domains', $result);
    }

    public function storeRedirect(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createRedirect(
            $id,
            trim((string) $request->input('domain', '')),
            trim((string) $request->input('path', '')),
            trim((string) $request->input('destination', '')),
            (string) $request->input('type', 'permanent')
        );

        return $this->render($id, $service, 'domains', $result);
    }

    public function destroyRedirect(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteRedirect($id, (string) $request->input('source_domain', ''));

        return $this->render($id, $service, 'domains', $result);
    }

    public function storeCron(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->createCronJob(
            $id,
            trim((string) $request->input('minute', '*')),
            trim((string) $request->input('hour', '*')),
            trim((string) $request->input('day', '*')),
            trim((string) $request->input('month', '*')),
            trim((string) $request->input('weekday', '*')),
            (string) $request->input('command', '')
        );

        return $this->render($id, $service, 'cron', $result);
    }

    public function destroyCron(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteCronJob($id, (int) $request->input('line', 0));

        return $this->render($id, $service, 'cron', $result);
    }

    public function storeSshKey(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->generateSshKey(
            $id,
            trim((string) $request->input('name', '')),
            (string) $request->input('password', ''),
            (int) $request->input('key_size', 2048)
        );

        return $this->render($id, $service, 'ssh', $result);
    }

    public function destroySshKey(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->deleteSshKey($id, (string) $request->input('name', ''));

        return $this->render($id, $service, 'ssh', $result);
    }

    public function authorizeSshKey(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->authorizeSshKey($id, (string) $request->input('name', ''));

        return $this->render($id, $service, 'ssh', $result);
    }

    public function sso(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->ssoUrl($id, (string) $params['app']);

        if (!$result['success']) {
            return $this->render($id, $service, 'logins', $result);
        }

        return Response::redirect($result['url']);
    }

    /** @return array{0: ?array<string, mixed>, 1: ?Response} */
    private function requireOwnedService(int $id): array
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return [null, Response::redirect('/client/login')];
        }

        $service = $this->services->find($id);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return [null, Response::html('404 Not Found', 404)];
        }

        return [$service, null];
    }

    /** @param array<string, mixed> $service */
    private function render(int $serviceId, array $service, string $tab, ?array $notice): Response
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'email';

        $listing = match ($tab) {
            'email' => $this->tools->listEmailAccounts($serviceId),
            'ftp' => $this->tools->listFtpAccounts($serviceId),
            'databases' => $this->tools->listDatabases($serviceId),
            'dns' => $this->tools->listDnsRecords($serviceId),
            'cron' => $this->tools->listCronJobs($serviceId),
            'ssh' => $this->tools->listSshKeys($serviceId),
            'ssl' => $this->tools->listSslCertificates($serviceId),
            default => ['success' => true, 'message' => 'OK', 'data' => []],
        };

        $forwarders = $tab === 'email' ? $this->tools->listForwarders($serviceId) : ['data' => []];
        $autoresponders = $tab === 'email' ? $this->tools->listAutoresponders($serviceId) : ['data' => []];
        $addonDomains = $tab === 'domains' ? $this->tools->listAddonDomains($serviceId) : ['data' => []];
        $subdomains = $tab === 'domains' ? $this->tools->listSubdomains($serviceId) : ['data' => []];
        $redirects = $tab === 'domains' ? $this->tools->listRedirects($serviceId) : ['data' => []];
        $usage = $tab === 'usage' ? $this->tools->diskUsage($serviceId) : ['data' => null];

        $content = $this->view->render('cpanel-tools.index', [
            'service' => $service,
            'tab' => $tab,
            'items' => is_array($listing['data'] ?? null) ? $listing['data'] : [],
            'listError' => ($listing['success'] ?? false) ? null : ($listing['message'] ?? 'Request failed.'),
            'notice' => $notice,
            'dnsRecordTypes' => $this->tools->supportedDnsRecordTypes(),
            'forwarders' => is_array($forwarders['data'] ?? null) ? $forwarders['data'] : [],
            'autoresponders' => is_array($autoresponders['data'] ?? null) ? $autoresponders['data'] : [],
            'addonDomains' => is_array($addonDomains['data'] ?? null) ? $addonDomains['data'] : [],
            'subdomains' => is_array($subdomains['data'] ?? null) ? $subdomains['data'] : [],
            'redirects' => is_array($redirects['data'] ?? null) ? $redirects['data'] : [],
            'usage' => is_array($usage['data'] ?? null) ? $usage['data'] : null,
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'cPanel Tools — ' . (string) $service['product_name'],
            'content' => $content,
        ]));
    }
}
