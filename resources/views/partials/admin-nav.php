<?php
/** @var CodeVault\View $view */
/** @var array{brandName: string, logoUrl: ?string}|null $theme */
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null];

// Grouped + icon-based (blueprint §2 "same design throughout", R17 redesign)
// — replaces the earlier flat ~40-item list, which had grown unwieldy as
// rounds added screens. Each group is a native <details> (zero new JS,
// keyboard-accessible, works with CSP script-src 'self') so a staff member
// who never touches Domains can collapse it away.
$groups = [
    'Overview' => ['icon' => 'home', 'links' => [
        '/admin' => 'Dashboard',
    ]],
    'Billing' => ['icon' => 'billing', 'links' => [
        '/admin/orders' => 'Orders',
        '/admin/invoices' => 'Invoices',
        '/admin/import/invoices' => 'Import Invoices',
        '/admin/import/transactions' => 'Import Transactions',
        '/admin/quotes' => 'Quotes',
        '/admin/credit-notes' => 'Credit Notes',
        '/admin/billable-items' => 'Billable Items',
        '/admin/gateways' => 'Payment Gateways',
        '/admin/tax-rules' => 'Tax Rules',
        '/admin/currencies' => 'Currencies',
        '/admin/promotions' => 'Promotions',
    ]],
    'Clients' => ['icon' => 'users', 'links' => [
        '/admin/clients' => 'Clients',
        '/admin/import/clients' => 'Import Clients',
        '/admin/import/whmcs' => 'Import from WHMCS',
        '/admin/custom-fields' => 'Custom Fields',
    ]],
    'Provisioning' => ['icon' => 'server', 'links' => [
        '/admin/services' => 'Services',
        '/admin/import/services' => 'Import Services',
        '/admin/servers' => 'Servers',
        '/admin/products' => 'Products',
        '/admin/products/groups' => 'Product Groups',
        '/admin/configurable-options' => 'Configurable Options',
        '/admin/import-resellerclub' => 'ResellerClub Import',
    ]],
    'Domains' => ['icon' => 'globe', 'links' => [
        '/admin/domains' => 'Domains',
        '/admin/domain-pricing' => 'Domain Pricing',
        '/admin/registrars' => 'Registrars',
    ]],
    'Support' => ['icon' => 'support', 'links' => [
        '/admin/tickets' => 'Support Tickets',
        '/admin/departments' => 'Departments',
        '/admin/canned-replies' => 'Canned Replies',
        '/admin/mail-piping' => 'Mail Piping',
        '/admin/kb/articles' => 'KB Articles',
        '/admin/kb/categories' => 'KB Categories',
        '/admin/downloads' => 'Downloads',
        '/admin/downloads/categories' => 'Download Categories',
        '/admin/announcements' => 'Announcements',
        '/admin/network-issues' => 'Network Issues',
    ]],
    'Marketing' => ['icon' => 'megaphone', 'links' => [
        '/admin/campaigns' => 'Campaigns',
        '/admin/affiliates' => 'Affiliates',
        '/admin/ai-visibility' => 'AI Visibility',
        '/admin/ask-ai' => 'Ask AI',
    ]],
    'Reports' => ['icon' => 'chart', 'links' => [
        '/admin/reports' => 'Reports',
    ]],
    'Security' => ['icon' => 'shield', 'links' => [
        '/admin/security' => 'BruteGuard',
        '/admin/account/security' => 'My Security',
        '/admin/security-questions' => 'Security Questions',
        '/admin/gdpr' => 'GDPR / Privacy',
    ]],
    'Configuration' => ['icon' => 'settings', 'links' => [
        '/admin/settings/general' => 'General Settings',
        '/admin/staff' => 'Staff',
        '/admin/roles' => 'Roles',
        '/admin/email-templates' => 'Email Templates',
        '/admin/languages' => 'Languages',
        '/admin/notification-endpoints' => 'Notifications',
        '/admin/theme' => 'Theme',
        '/admin/ai' => 'AI Copilot',
        '/admin/cron' => 'Cron & Automation',
        '/admin/backups' => 'Backups',
        '/admin/addons' => 'Addons',
        '/admin/widgets' => 'Widgets',
    ]],
];

$icons = [
    'home' => '<path d="M2 8.5 8 3l6 5.5"/><path d="M4 7v6.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 .5-.5V11a1.5 1.5 0 0 1 3 0v2.5a.5.5 0 0 0 .5.5h1.5a.5.5 0 0 0 .5-.5V7"/>',
    'billing' => '<rect x="1.5" y="3.5" width="13" height="9" rx="1.5"/><path d="M1.5 6.5h13"/><path d="M4 10h2"/>',
    'users' => '<circle cx="6" cy="5.5" r="2.5"/><path d="M1.5 14c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/><path d="M10.5 3.2a2.5 2.5 0 0 1 0 4.9"/><path d="M12.2 10.3c1.9.4 2.8 1.7 2.8 3.7"/>',
    'server' => '<rect x="2" y="2" width="12" height="4.5" rx="1"/><rect x="2" y="9" width="12" height="4.5" rx="1"/><path d="M4.25 4.25h.01"/><path d="M4.25 11.25h.01"/>',
    'globe' => '<circle cx="8" cy="8" r="6.25"/><path d="M1.75 8h12.5"/><path d="M8 1.75c1.7 1.8 2.6 4 2.6 6.25S9.7 12.45 8 14.25c-1.7-1.8-2.6-4-2.6-6.25S6.3 3.55 8 1.75z"/>',
    'support' => '<circle cx="8" cy="8" r="6.25"/><circle cx="8" cy="8" r="2.5"/><path d="M3.7 3.7l2.7 2.7M12.3 3.7l-2.7 2.7M3.7 12.3l2.7-2.7M12.3 12.3l-2.7-2.7"/>',
    'megaphone' => '<path d="M2 6.5v3a1 1 0 0 0 1 1h1l1.5 3.5V6.5"/><path d="M4 6.5 11 3v10L4 9.5"/><path d="M11 6h2.5"/><path d="M11.5 3.2c1.4.9 2.2 2.7 2.2 4.8s-.8 3.9-2.2 4.8"/>',
    'chart' => '<path d="M2 13.5h12"/><rect x="3.5" y="8" width="2.2" height="5.5"/><rect x="6.9" y="5" width="2.2" height="8.5"/><rect x="10.3" y="2.5" width="2.2" height="11"/>',
    'shield' => '<path d="M8 1.5 13.5 3.5v4c0 4-2.3 6.5-5.5 7.5-3.2-1-5.5-3.5-5.5-7.5v-4L8 1.5z"/><path d="M5.7 8.1l1.6 1.6 3-3.2"/>',
    'settings' => '<circle cx="8" cy="8" r="2.2"/><path d="M8 1.8v1.6M8 12.6v1.6M14.2 8h-1.6M3.4 8H1.8M12.3 3.7l-1.1 1.1M4.8 11.2l-1.1 1.1M12.3 12.3l-1.1-1.1M4.8 4.8 3.7 3.7"/>',
];

$currentPath = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

$groupHasActive = static function (array $links) use ($currentPath): bool {
    foreach ($links as $href => $label) {
        if ($currentPath === $href || ($href !== '/admin' && str_starts_with($currentPath, $href))) {
            return true;
        }
    }

    return false;
};
?>
<div class="cv-shell__sidebar-header" style="padding:var(--cv-space-4);border-bottom:1px solid var(--cv-border-default);display:flex;align-items:center;gap:var(--cv-space-2);">
    <?php if (!empty($theme['logoUrl'])): ?>
        <img src="<?= e($theme['logoUrl']) ?>" alt="<?= e($theme['brandName']) ?>" style="height:1.5rem;">
    <?php else: ?>
        <div style="width:1.75rem;height:1.75rem;border-radius:var(--cv-radius-sm);background:var(--cv-gradient-accent);flex-shrink:0;"></div>
        <span style="font-weight:800;font-size:var(--cv-text-lg);letter-spacing:-0.01em;"><?= e($theme['brandName']) ?></span>
    <?php endif; ?>
</div>
<nav class="cv-sidenav">
    <?php foreach ($groups as $groupLabel => $group): ?>
        <?php $open = $groupHasActive($group['links']); ?>
        <details class="cv-sidenav-group" <?= $open ? 'open' : '' ?>>
            <summary style="display:flex;align-items:center;gap:var(--cv-space-2);padding:0.4rem var(--cv-space-3);cursor:pointer;border-radius:var(--cv-radius-sm);list-style:none;">
                <svg class="cv-sidenav-link__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$group['icon']] ?></svg>
                <span class="cv-sidenav-group__label" style="padding:0;flex:1;"><?= e($groupLabel) ?></span>
                <svg width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" style="transition:transform var(--cv-transition-fast);flex-shrink:0;" class="cv-sidenav-chevron"><path d="M5 3l5 5-5 5"/></svg>
            </summary>
            <ul style="list-style:none;padding:0;margin:0.125rem 0 var(--cv-space-2);">
                <?php foreach ($group['links'] as $href => $label): ?>
                    <?php $active = $currentPath === $href || ($href !== '/admin' && str_starts_with($currentPath, $href)); ?>
                    <li>
                        <a class="cv-sidenav-link" href="<?= e($href) ?>" style="padding-left:2.375rem;" <?= $active ? "data-active='true'" : '' ?>>
                            <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
</nav>
<style>
.cv-sidenav-group summary::-webkit-details-marker { display: none; }
.cv-sidenav-group summary { transition: background-color var(--cv-transition-fast), color var(--cv-transition-fast); color: var(--cv-text-secondary); font-weight: 600; font-size: var(--cv-text-sm); }
.cv-sidenav-group summary:hover { background: var(--cv-bg-surface-sunken); color: var(--cv-text-primary); }
.cv-sidenav-group[open] > summary .cv-sidenav-chevron { transform: rotate(90deg); }
</style>
