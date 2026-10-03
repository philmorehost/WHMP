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
    '/client/reseller/store' => 'Store & branding',
    '/client/reseller/prices' => 'Your prices',
    '/client/reseller/promotions' => 'Promotions',
    '/client/reseller/tickets' => 'Support tickets',
    '/client/reseller/mail' => 'Support address',
    '/client/reseller/account' => 'Earnings & payouts',
    '/client/reseller/statements' => 'Statements',
    '/client/reseller/docs' => 'API docs',
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
                    <?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
