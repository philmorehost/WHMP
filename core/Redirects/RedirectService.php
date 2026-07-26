<?php

declare(strict_types=1);

namespace CodeVault\Redirects;

use CodeVault\Database;

/**
 * Handles 301 permanent redirects from old WHMCS URLs to new WHMP URLs.
 * Maps common WHMCS client area paths to their WHMP equivalents.
 */
final class RedirectService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Find a redirect target for an old URL path.
     * Returns null if no redirect mapping exists.
     */
    public function findRedirect(string $oldPath): ?string
    {
        $oldPath = trim($oldPath, '/');

        // Direct WHMCS URL mappings
        $mappings = [
            // Client area main pages
            'clientarea.php' => '/client/dashboard',
            'clientarea.php?action=emails' => '/client/account/profile',
            'clientarea.php?action=details' => '/client/account/profile',
            'clientarea.php?action=security' => '/client/account/security',
            'clientarea.php?action=contacts' => '/client/account/profile',
            'clientarea.php?action=affiliates' => '/client/affiliates',

            // Services
            'clientarea.php?action=services' => '/client/services',
            'clientarea.php?action=productdetails' => '/client/services',

            // Domains
            'clientarea.php?action=domains' => '/client/domains',
            'clientarea.php?action=domaindetails' => '/client/domains',

            // Support
            'supporttickets.php' => '/client/support/tickets',
            'clientarea.php?action=supporttickets' => '/client/support/tickets',
            'knowledgebase.php' => '/knowledgebase',

            // Invoices & Billing
            'clientarea.php?action=invoices' => '/client/invoices',
            'clientarea.php?action=viewinvoice' => '/client/invoices',
            'clientarea.php?action=quotes' => '/client/quotes',

            // Store/Cart
            'cart.php' => '/store',
            'cart.php?a=view' => '/store',
            'cart.php?a=checkout' => '/cart',
            'checkout.php' => '/cart',

            // Downloads
            'downloads.php' => '/downloads',

            // Announcements
            'announcements.php' => '/',

            // FAQ
            'faq.php' => '/knowledgebase',

            // Profile/Account
            'clientarea.php?action=profile' => '/client/account/profile',
            'clientarea.php?action=changepassword' => '/client/account/security',
        ];

        // Check direct mappings first
        if (isset($mappings[$oldPath])) {
            return $mappings[$oldPath];
        }

        // Parse WHMCS query parameters
        if (str_starts_with($oldPath, 'clientarea.php?action=viewproduct')) {
            return '/client/services';
        }

        if (str_starts_with($oldPath, 'cart.php?')) {
            return '/store';
        }

        // Default fallback for unknown client area pages
        if (str_starts_with($oldPath, 'clientarea.php') || str_starts_with($oldPath, 'cart.php')) {
            return '/client/dashboard';
        }

        return null;
    }

    /**
     * Check if a path should be redirected (returns true if redirect exists)
     */
    public function shouldRedirect(string $oldPath): bool
    {
        return $this->findRedirect($oldPath) !== null;
    }

    /**
     * Get redirect response with 301 status
     */
    public function getRedirectResponse(string $oldPath): ?array
    {
        $target = $this->findRedirect($oldPath);

        if ($target === null) {
            return null;
        }

        return [
            'status' => 301,
            'location' => $target,
        ];
    }

    /**
     * Get all registered redirect mappings (for admin purposes)
     */
    public function getAllMappings(): array
    {
        return [
            // Client area main pages
            'clientarea.php' => '/client/dashboard',
            'clientarea.php?action=emails' => '/client/account/profile',
            'clientarea.php?action=details' => '/client/account/profile',
            'clientarea.php?action=security' => '/client/account/security',
            'clientarea.php?action=contacts' => '/client/account/profile',
            'clientarea.php?action=affiliates' => '/client/affiliates',
            'clientarea.php?action=services' => '/client/services',
            'clientarea.php?action=productdetails' => '/client/services',
            'clientarea.php?action=domains' => '/client/domains',
            'clientarea.php?action=domaindetails' => '/client/domains',
            'supporttickets.php' => '/client/support/tickets',
            'clientarea.php?action=supporttickets' => '/client/support/tickets',
            'knowledgebase.php' => '/knowledgebase',
            'clientarea.php?action=invoices' => '/client/invoices',
            'clientarea.php?action=viewinvoice' => '/client/invoices',
            'clientarea.php?action=quotes' => '/client/quotes',
            'cart.php' => '/store',
            'cart.php?a=view' => '/store',
            'cart.php?a=checkout' => '/cart',
            'checkout.php' => '/cart',
            'downloads.php' => '/downloads',
            'announcements.php' => '/',
            'faq.php' => '/knowledgebase',
            'clientarea.php?action=profile' => '/client/account/profile',
            'clientarea.php?action=changepassword' => '/client/account/security',
        ];
    }
}
