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

        $zone = trim((string) $request->query('zone', ''));

        return $this->render($id, $service, (string) $request->query('tab', 'logins'), null, $zone !== '' ? $zone : null);
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

        $stopInput = trim((string) $request->input('stop', ''));
        $stop = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $stopInput);
        $dateErrors = \DateTimeImmutable::getLastErrors();

        if ($stop === false
            || $stop->format('Y-m-d\\TH:i') !== $stopInput
            || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            return $this->render($id, $service, 'email', ['success' => false, 'message' => 'Choose a valid autoresponder end date and time.', 'data' => []]);
        }

        $result = $this->tools->createAutoresponder(
            $id,
            trim((string) $request->input('local_part', '')),
            trim((string) $request->input('subject', '')),
            (string) $request->input('body', ''),
            $stop->getTimestamp()
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

        $result = $this->tools->deleteAutoresponder($id, (string) $request->input('email', ''));

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

        $password = (string) $request->input('password', '');
        $passwordConfirmation = (string) $request->input('confirm_password', '');

        if ($password !== $passwordConfirmation) {
            return $this->render($id, $service, 'databases', [
                'success' => false,
                'message' => 'The database passwords do not match. Nothing was created.',
                'data' => [],
            ]);
        }

        $result = $this->tools->createDatabase(
            $id,
            trim((string) $request->input('name', '')),
            trim((string) $request->input('user', '')),
            $password
        );

        return $this->render($id, $service, 'databases', $result);
    }

    public function changeDatabaseUserPassword(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $password = (string) $request->input('password', '');
        $passwordConfirmation = (string) $request->input('confirm_password', '');

        if ($password !== $passwordConfirmation) {
            return $this->render($id, $service, 'databases', [
                'success' => false,
                'message' => 'The new database-user passwords do not match. No password was changed.',
                'data' => [],
            ]);
        }

        $result = $this->tools->setDatabaseUserPassword(
            $id,
            (string) $request->input('user', ''),
            $password
        );

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

        $zone = trim((string) $request->input('zone', ''));
        $result = $this->tools->addDnsRecord(
            $id,
            trim((string) $request->input('name', '')),
            (string) $request->input('type', 'A'),
            trim((string) $request->input('value', '')),
            (int) $request->input('ttl', 14400),
            $zone !== '' ? $zone : null
        );

        return $this->render($id, $service, 'dns', $result, $zone !== '' ? $zone : null);
    }

    public function destroyDns(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $zone = trim((string) $request->input('zone', ''));
        $result = $this->tools->deleteDnsRecord(
            $id,
            (int) $request->input('line', -1),
            (string) $request->input('name', ''),
            (string) $request->input('type', ''),
            (string) $request->input('value', ''),
            $zone !== '' ? $zone : null
        );

        return $this->render($id, $service, 'dns', $result, $zone !== '' ? $zone : null);
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
            trim((string) $request->input('source', '')),
            trim((string) $request->input('target', '')),
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

        $result = $this->tools->deleteRedirect(
            $id,
            (string) $request->input('domain', ''),
            (string) $request->input('source', '')
        );

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

    public function storeSslCertificate(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        [$service, $denied] = $this->requireOwnedService($id);

        if ($denied !== null) {
            return $denied;
        }

        $result = $this->tools->installSslCertificate(
            $id,
            trim((string) $request->input('domain', '')),
            (string) $request->input('certificate', ''),
            (string) $request->input('private_key', ''),
            (string) $request->input('ca_bundle', '')
        );

        return $this->render($id, $service, 'ssl', $result);
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
    private function render(int $serviceId, array $service, string $tab, ?array $notice, ?string $selectedDnsZone = null): Response
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'email';

        $listing = match ($tab) {
            'email' => $this->tools->listEmailAccounts($serviceId),
            'ftp' => $this->tools->listFtpAccounts($serviceId),
            'databases' => $this->tools->listDatabases($serviceId),
            'dns' => $this->tools->listDnsRecords($serviceId, $selectedDnsZone),
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
        $databaseUsers = $tab === 'databases' ? $this->tools->listDatabaseUsers($serviceId) : ['data' => []];

        $additionalResults = match ($tab) {
            'email' => [
                'Email forwarders' => $forwarders,
                'Autoresponders' => $autoresponders,
            ],
            'databases' => ['Database users' => $databaseUsers],
            'domains' => [
                'Addon domains' => $addonDomains,
                'Subdomains' => $subdomains,
                'Redirects' => $redirects,
            ],
            'usage' => ['Disk usage' => $usage],
            default => [],
        };
        $errors = [];

        if (!($listing['success'] ?? false)) {
            $errors[] = (string) ($listing['message'] ?? 'The cPanel listing request failed.');
        }

        foreach ($additionalResults as $label => $result) {
            if (array_key_exists('success', $result) && !$result['success']) {
                $errors[] = $label . ': ' . (string) ($result['message'] ?? 'The cPanel request failed.');
            }
        }

        $usageData = null;

        if (($usage['success'] ?? false) && is_array($usage['data'] ?? null)) {
            $rawUsage = $usage['data'];

            if (isset($rawUsage['usedMb'], $rawUsage['limitMb'])
                && is_numeric($rawUsage['usedMb'])
                && is_numeric($rawUsage['limitMb'])) {
                $usageData = [
                    'usedMb' => (float) $rawUsage['usedMb'],
                    'limitMb' => (float) $rawUsage['limitMb'],
                ];
            }
        }

        $dnsZones = is_array($listing['zones'] ?? null) ? $listing['zones'] : [];
        $selectedDnsZoneName = trim((string) ($listing['zone'] ?? ''));

        $content = $this->view->render('cpanel-tools.index', [
            'service' => $service,
            'tab' => $tab,
            'items' => is_array($listing['data'] ?? null) ? $listing['data'] : [],
            'listError' => $errors !== [] ? implode(' ', $errors) : null,
            'notice' => $notice,
            'dnsRecordTypes' => $this->tools->supportedDnsRecordTypes(),
            'dnsZones' => $dnsZones,
            'selectedDnsZone' => $selectedDnsZoneName,
            'forwarders' => is_array($forwarders['data'] ?? null) ? $forwarders['data'] : [],
            'autoresponders' => is_array($autoresponders['data'] ?? null) ? $autoresponders['data'] : [],
            'addonDomains' => is_array($addonDomains['data'] ?? null) ? $addonDomains['data'] : [],
            'subdomains' => is_array($subdomains['data'] ?? null) ? $subdomains['data'] : [],
            'redirects' => is_array($redirects['data'] ?? null) ? $redirects['data'] : [],
            'usage' => $usageData,
            'databaseUsers' => is_array($databaseUsers['data'] ?? null) ? $databaseUsers['data'] : [],
        ]);
        return Response::html($this->view->render('layouts.client', [
            'title' => 'cPanel Tools — ' . (string) $service['product_name'],
            'content' => $content,
        ]));
    }
}
