<?php
/**
 * The admin's reseller-management navigation — ONE copy for the programme-wide pages
 * (the overview and the queues that span every reseller). A page about ONE reseller uses
 * partials/reseller-admin-head instead.
 *
 * Like the client-side nav, the active item is derived from the request path rather than
 * passed in, so a page cannot forget to set it.
 *
 * @var string|null $active override for an unusual page; normally leave unset
 */

$active = isset($active) ? (string) $active : '';
$currentPath = rtrim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');

$items = [
    '/admin/resellers' => ['Overview', '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>'],
    '/admin/resellers/accounts' => ['Accounts', '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>'],
    '/admin/resellers/payouts' => ['Payouts', '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
    '/admin/resellers/billing' => ['Cost billing', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>'],
    '/admin/resellers/domains' => ['Domains', '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
    '/admin/resellers/escalations' => ['Escalations', '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
    '/admin/resellers/migrations' => ['Account moves', '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>'],
    '/admin/resellers/docs' => ['API docs', '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>'],
];

if ($active === '') {
    foreach (array_keys($items) as $path) {
        // The overview is a prefix of everything, so it only counts on an exact match.
        $isMatch = $path === '/admin/resellers'
            ? $currentPath === $path
            : ($currentPath === $path || str_starts_with($currentPath, $path . '/'));

        if ($isMatch && strlen($path) > strlen($active)) {
            $active = $path;
        }
    }
}
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<nav class="rs-nav rs-nav--admin" aria-label="Reseller management">
    <ul class="rs-nav__list">
        <?php foreach ($items as $path => [$label, $paths]): ?>
            <?php $isActive = $path === $active; ?>
            <li>
                <a class="rs-nav__link<?= $isActive ? ' rs-nav__link--active' : '' ?>"
                   href="<?= e($path) ?>"
                   <?php if ($isActive): ?>aria-current="page"<?php endif; ?>>
                    <svg class="rs-nav__icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $paths ?></svg>
                    <span><?= e($label) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
