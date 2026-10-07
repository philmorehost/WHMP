<?php
/**
 * The reseller area's navigation — ONE copy, for every page under /client/reseller.
 *
 * WHY THIS EXISTS
 *
 * The same list of links was written out by hand in eight views, each with its own subset
 * and its own wording. Adding a page meant editing several files, and missing one produced a
 * page that was simply unreachable from wherever the link was forgotten — which is exactly
 * how the support desk and the support address shipped with only a partial set of links
 * between them. Two of those manual edits are already in this file's history.
 *
 * The stylesheet is emitted with the nav rather than by each view, so a new page cannot
 * forget it and the portal cannot end up half-styled.
 *
 * THE ACTIVE ITEM IS DERIVED FROM THE REQUEST, NOT PASSED IN
 *
 * Deliberately. A `$active` variable is one more thing each controller has to remember to
 * set, and the failure is silent: the page renders with no item highlighted and nobody
 * notices. Deriving it from the path costs nothing and cannot drift.
 *
 * @var string|null $active override for an unusual page; normally leave unset
 */

if (!isset($active)) {
    $active = '';
}

$currentPath = rtrim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');

$items = [
    '/client/reseller' => 'Overview',
    '/client/reseller/clients' => 'Customers',
    '/client/reseller/store' => 'Store & branding',
    '/client/reseller/prices' => 'Your prices',
    '/client/reseller/promotions' => 'Promotions',
    '/client/reseller/tickets' => 'Support tickets',
    '/client/reseller/migrations' => 'Account moves',
    '/client/reseller/mail' => 'Support address',
    '/client/reseller/account' => 'Earnings & payouts',
    '/client/reseller/statements' => 'Statements',
    '/client/reseller/docs' => 'API docs',
];

// Username requests — only while the super admin has the cPanel Username Changer add-on on
// and open to reseller stores. Any failure (no container in a unit test, table not migrated
// yet) simply leaves the link out.
try {
    $ucnContainer = \CodeVault\Support\App::container();
    if ($ucnContainer->make(\CodeVault\Modules\AddonModuleRepository::class)->isActive(\CodeVault\UsernameChanger\UsernameChangeCronJob::SLUG)
        && $ucnContainer->make(\CodeVault\UsernameChanger\UsernameChangerSettings::class)->storesAllowed()) {
        $items = array_slice($items, 0, 5, true)
            + ['/client/reseller/username-requests' => 'Username requests']
            + array_slice($items, 5, null, true);
    }
} catch (\Throwable) {
    // leave the nav as it is
}

// One stroke icon per destination (24px grid, currentColor) so the bar reads at a glance
// and inherits the active/hover colour without a second asset.
$icons = [
    '/client/reseller' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    '/client/reseller/clients' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    '/client/reseller/store' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>',
    '/client/reseller/prices' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    '/client/reseller/promotions' => '<path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    '/client/reseller/tickets' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    '/client/reseller/migrations' => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    '/client/reseller/mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
    '/client/reseller/account' => '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
    '/client/reseller/statements' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
    '/client/reseller/docs' => '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>',
    '/client/reseller/username-requests' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h1"/><path d="M17.5 14.5l2 2-4.5 4.5H13v-2z"/>',
];

if ($active === '') {
    foreach ($items as $path => $label) {
        // The overview is a PREFIX of every other path, so it only counts on an exact match —
        // otherwise every page would light up "Overview" alongside its own item. The rest
        // match themselves or a child, so /client/reseller/statements/12 highlights
        // Statements rather than nothing.
        $isMatch = $path === '/client/reseller'
            ? $currentPath === $path
            : ($currentPath === $path || str_starts_with($currentPath, $path . '/'));

        // Longest match wins, so a future nested page cannot be claimed by a shorter prefix.
        if ($isMatch && strlen($path) > strlen($active)) {
            $active = $path;
        }
    }
}
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<nav class="rs-nav" aria-label="Reseller area">
    <ul class="rs-nav__list">
        <?php foreach ($items as $path => $label): ?>
            <?php $isActive = $path === $active; ?>
            <li>
                <a class="rs-nav__link<?= $isActive ? ' rs-nav__link--active' : '' ?>"
                   href="<?= e($path) ?>"
                   <?php if ($isActive): ?>aria-current="page"<?php endif; ?>>
                    <svg class="rs-nav__icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $icons[$path] ?? '' ?></svg>
                    <span><?= e($label) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
