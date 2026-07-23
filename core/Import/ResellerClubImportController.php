<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Auth\AuthGuard;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ResellerClubImportController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductGroupRepository $groups,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly ServerGroupRepository $serverGroups,
        private readonly ServerRepository $servers
    ) {
    }

    public function form(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('import.resellerclub', [
            'success' => null,
            'error' => null,
        ]);
    }

    public function run(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $markupType = (string) $request->input('markup_type', 'fixed');
        $markupValue = (float) $request->input('markup_value', 0.0);

        // Find or create product group
        $group = $this->groups->findByName('ResellerClub Email Hosting');
        $groupId = $group ? (int) $group['id'] : $this->groups->create('ResellerClub Email Hosting', 'Professional email services provisioned via ResellerClub.');

        // Find or create server group
        $serverGroup = $this->serverGroups->findByName('ResellerClub Email Group');
        $serverGroupId = $serverGroup ? (int) $serverGroup['id'] : $this->serverGroups->create('ResellerClub Email Group');

        // Check if server is configured under this group
        $allServers = $this->servers->all();
        $serverExists = false;
        foreach ($allServers as $srv) {
            if ((string) ($srv['module_slug'] ?? '') === 'resellerclub-email') {
                $serverExists = true;
                break;
            }
        }

        if (!$serverExists) {
            // Create a default ResellerClub Email server
            $this->servers->create([
                'server_group_id' => $serverGroupId,
                'name' => 'ResellerClub Email API',
                'hostname' => 'httpapi.com',
                'module_slug' => 'resellerclub-email',
                'api_username' => 'API',
                'api_token' => 'API',
                'api_port' => 443,
                'use_ssl' => 1,
                'active' => 1,
            ]);
        }

        // Standard ResellerClub product list
        $plans = [
            [
                'name' => 'Business Email',
                'description' => 'Professional email address for your business with rich webmail access, anti-spam protection, and 5GB storage.',
                'cost' => 0.50,
            ],
            [
                'name' => 'Enterprise Email',
                'description' => 'Advanced corporate email hosting. Shared calendar, contacts, tasks, collaboration tools, and 30GB storage.',
                'cost' => 1.00,
            ],
            [
                'name' => 'Titan Email Hosting',
                'description' => 'Premium, lightning-fast business email by Titan. Includes read receipts, email templates, follow-up reminders, and desktop/mobile apps.',
                'cost' => 1.50,
            ],
            [
                'name' => 'Google Workspace Business Starter',
                'description' => 'Get Gmail, Drive, Meet, Calendar, and Chat for your team. Includes 30GB secure cloud storage per user.',
                'cost' => 6.00,
            ],
        ];

        $imported = 0;
        foreach ($plans as $plan) {
            $retailPrice = $plan['cost'];
            if ($markupType === 'fixed') {
                $retailPrice += $markupValue;
            } elseif ($markupType === 'percentage') {
                $retailPrice *= (1 + ($markupValue / 100));
            }
            $retailPrice = round($retailPrice, 2);

            $existing = $this->products->findByName($plan['name']);
            if ($existing !== null) {
                // Update product server group linkage and description
                $this->products->update((int) $existing['id'], [
                    'product_group_id' => $groupId,
                    'server_group_id' => $serverGroupId,
                    'autosetup' => 'payment',
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'status' => 'active',
                    'type' => 'other',
                    'require_domain' => 1,
                ]);

                // Set pricing
                $this->pricing->setPricing((int) $existing['id'], 'monthly', 0.00, $retailPrice);
                $this->pricing->setPricing((int) $existing['id'], 'annually', 0.00, $retailPrice * 12);
                $imported++;
                continue;
            }

            // Create product
            $prodId = $this->products->create([
                'product_group_id' => $groupId,
                'server_group_id' => $serverGroupId,
                'autosetup' => 'payment',
                'name' => $plan['name'],
                'description' => $plan['description'],
                'status' => 'active',
                'type' => 'other',
                'require_domain' => 1,
            ]);

            $this->pricing->setPricing($prodId, 'monthly', 0.00, $retailPrice);
            $this->pricing->setPricing($prodId, 'annually', 0.00, $retailPrice * 12);
            $imported++;
        }

        return $this->render('import.resellerclub', [
            'success' => "Successfully imported/updated {$imported} email plans under the 'ResellerClub Email Hosting' product group with your specified markup.",
            'error' => null,
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PRODUCTS_MANAGE)) {
            return Response::html('403 Forbidden — missing products.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — ResellerClub Email Import',
            'content' => $content,
        ]));
    }
}
