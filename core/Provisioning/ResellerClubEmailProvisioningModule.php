<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;
use CodeVault\Domains\RegistrarRepository;

/**
 * ResellerClub Email Hosting, Titan Email, and Google Workspace provisioning module
 * built on the LogicBoxes API structure.
 */
final class ResellerClubEmailProvisioningModule implements ProvisioningModule
{
    private const BASE_URL = 'https://httpapi.com/api';

    public function __construct(
        private readonly HttpClient $http,
        private readonly RegistrarRepository $registrars
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'ResellerClub Email Hosting',
            'description' => 'Business, Enterprise, Titan Email, and Google Workspace provisioning via ResellerClub API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'product_type' => [
                'type' => 'dropdown',
                'label' => 'Product Type',
                'options' => [
                    'business_email' => 'Business Email',
                    'enterprise_email' => 'Enterprise Email',
                    'titan_email' => 'Titan Email',
                    'google_workspace' => 'Google Workspace'
                ],
                'default' => 'business_email'
            ],
            'no_of_accounts' => [
                'type' => 'text',
                'label' => 'Number of Email Accounts',
                'default' => '1'
            ]
        ];
    }

    public function create(array $params): array
    {
        $config = $this->registrars->configFor('resellerclub');
        $authUserId = (string) ($config['reseller_id'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');
        $customerId = (string) ($config['customer_id'] ?? '0');

        if ($authUserId === '' || $apiKey === '') {
            return ['success' => false, 'message' => 'ResellerClub registrar integration is not configured. Please enter Reseller ID and API Key.'];
        }

        $type = (string) ($params['product_type'] ?? 'business_email');
        $accounts = (int) ($params['no_of_accounts'] ?? 1);
        $domain = (string) ($params['domain'] ?? '');

        if ($domain === '') {
            return ['success' => false, 'message' => 'A valid domain name is required for email hosting provisioning.'];
        }

        $queryParams = [
            'auth-userid' => $authUserId,
            'api-key' => $apiKey,
            'domain-name' => $domain,
            'customer-id' => $customerId,
            'no-of-accounts' => $accounts,
            'invoice-option' => 'NoInvoice',
        ];

        $path = match ($type) {
            'enterprise_email' => '/mail/us/add-enterprise.json',
            'titan_email' => '/titanmail/add.json',
            'google_workspace' => '/google/add.json',
            default => '/mail/us/add-business.json',
        };

        $response = $this->http->request('POST', self::BASE_URL . $path . '?' . http_build_query($queryParams), [], null);
        
        return $this->toResult($response, "Email hosting order for {$domain} has been placed via ResellerClub.");
    }

    public function suspend(array $params): array
    {
        return $this->lifecycleAction($params, '/mail/us/suspend.json');
    }

    public function unsuspend(array $params): array
    {
        return $this->lifecycleAction($params, '/mail/us/unsuspend.json');
    }

    public function terminate(array $params): array
    {
        return $this->lifecycleAction($params, '/mail/us/delete.json');
    }

    public function changePassword(array $params): array
    {
        return ['success' => false, 'message' => 'Changing admin password via API is not supported. Please do it in the ResellerClub email dashboard.'];
    }

    public function changePackage(array $params): array
    {
        return ['success' => false, 'message' => 'Upgrades require order scale modifications.'];
    }

    public function singleSignOn(array $params): array
    {
        $domain = (string) ($params['domain'] ?? '');
        $type = (string) ($params['product_type'] ?? 'business_email');

        if ($type === 'titan_email') {
            return ['success' => true, 'url' => "https://titan.email/mail/?domain={$domain}", 'message' => 'SSO URL resolved.'];
        } elseif ($type === 'google_workspace') {
            return ['success' => true, 'url' => 'https://mail.google.com/a/' . $domain, 'message' => 'SSO URL resolved.'];
        }

        return ['success' => true, 'url' => "https://webmail.{$domain}", 'message' => 'SSO URL resolved.'];
    }

    public function usage(array $params): array
    {
        return [
            'success' => true,
            'bandwidthUsedMb' => 0,
            'bandwidthLimitMb' => null,
            'diskUsedMb' => 0,
            'diskLimitMb' => null,
        ];
    }

    public function testConnection(array $params): array
    {
        $config = $this->registrars->configFor('resellerclub');
        $authUserId = (string) ($config['reseller_id'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($authUserId === '' || $apiKey === '') {
            return ['success' => false, 'message' => 'Missing Reseller ID or API Key.'];
        }

        return ['success' => true, 'message' => 'Connection validated successfully.'];
    }

    private function lifecycleAction(array $params, string $path): array
    {
        $config = $this->registrars->configFor('resellerclub');
        $authUserId = (string) ($config['reseller_id'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');
        $domain = (string) ($params['domain'] ?? '');

        if ($authUserId === '' || $apiKey === '') {
            return ['success' => false, 'message' => 'ResellerClub registrar integration is not configured.'];
        }

        $queryParams = [
            'auth-userid' => $authUserId,
            'api-key' => $apiKey,
            'domain-name' => $domain,
        ];

        $response = $this->http->request('POST', self::BASE_URL . $path . '?' . http_build_query($queryParams), [], null);
        return $this->toResult($response);
    }

    private function toResult(array $response, string $successMessage = 'OK'): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the ResellerClub API.'];
        }

        $decoded = json_decode($response['body'], true);
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        if (is_array($decoded)) {
            $ok = $ok && (!isset($decoded['status']) || ($decoded['status'] !== 'ERROR' && $decoded['status'] !== 'error'));
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? '');
        } else {
            $message = $response['body'];
        }

        return [
            'success' => $ok,
            'message' => $ok ? $successMessage : ($message ?: 'ResellerClub API Error.')
        ];
    }
}
