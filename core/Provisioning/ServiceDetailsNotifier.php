<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Config;
use CodeVault\Domains\DomainSettings;
use CodeVault\Mail\EmailDispatcher;

/**
 * Sends a client the access details for a service — hostname/IPs/login for a
 * server product, domain/username for anything else — via the
 * `service_details` email template.
 *
 * Called "service details" throughout, not "server details": a product does
 * not have to be a physical machine to have login details worth emailing —
 * a licensed web app or a domain-bound product has a domain and a username
 * with nothing that could be called a server. The wording and the "is there
 * anything to send" check below both treat those the same as a VPS.
 *
 * Two callers, deliberately:
 *
 *  - Order acceptance, automatically. This is the common path when a product
 *    provisions through a module and the credentials already exist.
 *  - The admin service page, on demand. Manual provisioning is the reverse
 *    order: the admin approves first, then types the details in. An
 *    auto-send at approval time would have nothing to say, so send() is
 *    also a button.
 *
 * Because of that split, sendForService() refuses to send an empty email:
 * with nothing recorded there is nothing useful to tell the client, and a
 * blank "here are your details" is worse than silence. The admin sends it
 * once the details are filled in.
 */
final class ServiceDetailsNotifier
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly EmailDispatcher $mail,
        private readonly DomainSettings $domainSettings,
        private readonly ServerRepository $servers,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{sent: bool, reason: string}
     */
    public function sendForService(int $serviceId): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return ['sent' => false, 'reason' => 'Service not found.'];
        }

        $email = trim((string) ($service['client_email'] ?? ''));

        if ($email === '') {
            return ['sent' => false, 'reason' => 'Client has no email address on file.'];
        }

        $username = trim((string) ($service['username'] ?? ''));
        $hostname = trim((string) ($service['hostname'] ?? ''));
        $primaryIp = trim((string) ($service['dedicated_ip'] ?? ''));
        // A shared-hosting or domain-bound product may have nothing but a
        // domain and a username — no hostname/IP a client would recognise as
        // "server" info. Counting it here is what lets those products use
        // this same button instead of being permanently stuck refusing to
        // send.
        $domain = trim((string) ($service['domain'] ?? ''));

        if ($username === '' && $hostname === '' && $primaryIp === '' && $domain === '') {
            return [
                'sent' => false,
                'reason' => 'No service details recorded yet — add a username, domain, hostname or IP first, then send.',
            ];
        }

        $this->mail->sendTemplate('service_details', $email, $this->variables($service), (int) $service['client_id']);

        // Stamped only after the dispatcher accepted it, so a failure doesn't
        // leave the service claiming details were sent when they weren't.
        $this->services->stampDetailsSent($serviceId);

        $alreadySent = ($service['details_sent_at'] ?? null) !== null;

        return [
            'sent' => true,
            'reason' => ($alreadySent ? 'Service details re-sent to ' : 'Service details emailed to ') . $email . '.',
        ];
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, string>
     */
    private function variables(array $service): array
    {
        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
        $nameservers = $this->domainSettings->defaultNameservers();

        // Every value is rendered into an HTML table cell, so a blank one would
        // read as a broken row. "Not applicable" says the field simply doesn't
        // apply to this product rather than leaving the client guessing.
        $orDash = static fn (string $value): string => $value !== '' ? $value : 'Not applicable';

        $assignedIps = array_filter(array_map(
            'trim',
            explode("\n", str_replace("\r", '', (string) ($service['assigned_ips'] ?? '')))
        ));

        return [
            'first_name' => (string) ($service['first_name'] ?? 'there'),
            'product_name' => (string) ($service['product_name'] ?? 'your service'),
            'domain' => $orDash(trim((string) ($service['domain'] ?? ''))),
            'hostname' => $orDash(trim((string) ($service['hostname'] ?? ''))),
            'primary_ip' => $orDash(trim((string) ($service['dedicated_ip'] ?? ''))),
            'assigned_ips' => $orDash(implode(', ', $assignedIps)),
            'username' => $orDash(trim((string) ($service['username'] ?? ''))),
            'password' => $orDash(trim((string) ($service['password'] ?? ''))),
            'control_panel_url' => $this->controlPanelUrl($service),
            'nameservers' => $nameservers !== [] ? implode(', ', $nameservers) : 'Not applicable',
            'service_url' => $baseUrl . '/client/services/' . (int) $service['id'],
            'company_name' => brand_name(),
            'access_instructions' => $this->accessInstructions($service, $baseUrl),
        ];
    }

    /**
     * Product-specific "how do I actually log in" guidance.
     *
     * The template's `{{access_instructions}}` sits bare in the body (not
     * wrapped in a <p>), so returning '' makes the whole paragraph disappear
     * rather than leaving an empty one — the same trick as the rest of this
     * template, just at block level instead of per-field.
     *
     * Only cPanel gets a paragraph today, because it is the one case where
     * WHMP can point the client at something that actually works: one-click
     * SSO from the Client Area, no separate cPanel login needed. VPS/
     * dedicated control-panel self-service (backup, restore, console) is not
     * wired to a real provider action yet, so promising it here would be
     * telling the client about a button that does nothing.
     *
     * @param array<string, mixed> $service
     */
    private function accessInstructions(array $service, string $baseUrl): string
    {
        $serverId = $service['server_id'] ?? null;

        if ($serverId === null) {
            return '';
        }

        $server = $this->servers->find((int) $serverId);

        if ($server === null || (string) ($server['module_slug'] ?? '') !== 'cpanel') {
            return '';
        }

        $serviceUrl = $baseUrl . '/client/services/' . (int) $service['id'];

        return '<p><strong>To access your cPanel account:</strong> log in to your '
            . '<a href="' . e($serviceUrl) . '">Client Area</a>, open this service, and click '
            . '&ldquo;Log In to Control Panel&rdquo; for one-click access — you will not need to type the '
            . 'username or password above. If you would rather log in directly, use the username and '
            . 'password above at the address under &ldquo;Control panel&rdquo; below.</p>';
    }

    /**
     * A best-effort link to whatever panel this service is actually on.
     *
     * Prefers the service's own hostname over the shared server hostname: on a
     * VPS or dedicated box the client's panel lives on their machine, not on
     * the provisioning server we talked to. Falls back to the IP so the client
     * still has something to open before DNS resolves.
     *
     * @param array<string, mixed> $service
     */
    private function controlPanelUrl(array $service): string
    {
        $host = trim((string) ($service['hostname'] ?? ''));

        if ($host === '') {
            $host = trim((string) ($service['dedicated_ip'] ?? ''));
        }

        if ($host === '') {
            return 'Not applicable';
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            return $host;
        }

        return 'https://' . $host . ':2083';
    }
}
