<?php
/**
 * The header on every admin page ABOUT one reseller: who it is (Reseller ID and the
 * owner's User ID), and the way between its pages — store settings, its customers,
 * its account — plus signing in to the reseller's own control panel.
 *
 * Strict reseller isolation: a reseller's customers are reached only from here, so
 * this header is where an admin always starts.
 *
 * @var array<string, mixed> $store
 * @var array<string, mixed>|null $owner
 * @var string|null $current 'store' | 'customers' | 'account'
 * @var array<string, mixed>|null $upline the store the owner registered under, when the page has it
 */

$current = $current ?? '';
$ownerId = (int) $store['client_id'];
$brand = trim((string) ($store['brand_name'] ?? '')) !== '' ? (string) $store['brand_name'] : (string) $store['slug'];
$ownerName = is_array($owner ?? null) ? trim((string) $owner['first_name'] . ' ' . (string) $owner['last_name']) : '';
// A sub-reseller: the owner registered on another store. Read off the owner row the
// page already has, so no page needs to pass anything extra for this badge.
$uplineStoreId = is_array($owner ?? null) ? (int) ($owner['reseller_id'] ?? 0) : 0;
$uplineStoreId = $uplineStoreId === (int) $store['id'] ? 0 : $uplineStoreId;
$uplineLabel = '';
if (is_array($upline ?? null)) {
    $uplineLabel = trim((string) ($upline['brand_name'] ?? '')) !== '' ? (string) $upline['brand_name'] : (string) $upline['slug'];
}
$svg = static fn (string $paths): string => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
$tabs = [
    'store' => ['/admin/resellers/' . $ownerId . '/store', 'Store', '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/>'],
    'customers' => ['/admin/resellers/' . $ownerId . '/customers', 'Customers', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'],
    'account' => ['/admin/resellers/' . $ownerId . '/account', 'Account & earnings', '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>'],
];
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<div class="cv-card rs-admin-head rs-hero-card" style="margin-bottom:var(--cv-space-4);">
    <p class="rs-cust-back"><a href="/admin/resellers">&larr; All resellers</a></p>
    <div class="rs-admin-head__row">
        <div class="rs-admin-head__who">
            <span class="rs-avatar" aria-hidden="true"><?= e(function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($brand, 0, 1)) : strtoupper(substr($brand, 0, 1))) ?></span>
            <div>
                <h1 class="rs-head__title" style="margin:0;"><?= e($brand) ?></h1>
                <p class="rs-admin-head__ids">
                    <span class="rs-id-badge" title="This reseller's unique ID">Reseller ID <?= (int) $store['id'] ?></span>
                    <span class="rs-id-badge rs-id-badge--user" title="The reseller's own user account">User ID <?= $ownerId ?></span>
                    <?php if ($uplineStoreId > 0): ?>
                        <span class="rs-chip rs-chip--tier" title="Registered on another reseller's store, so buys at that store's prices">Sub-reseller of <?= $uplineLabel !== '' ? e($uplineLabel) . ' ·' : '' ?> Reseller ID <?= $uplineStoreId ?></span>
                    <?php else: ?>
                        <span class="rs-chip">Partner reseller</span>
                    <?php endif; ?>
                    <?php if (($store['status'] ?? 'active') !== 'active'): ?><span class="cv-badge cv-badge--danger">Store suspended</span><?php endif; ?>
                </p>
                <?php if ($ownerName !== ''): ?><p class="rs-cust-sub" style="margin:0;"><?= e($ownerName) ?><?= !empty($owner['email']) ? ' · ' . e((string) $owner['email']) : '' ?></p><?php endif; ?>
            </div>
        </div>
        <div class="rs-cust-hero__actions">
            <form method="post" action="/admin/resellers/<?= $ownerId ?>/login" target="_blank">
                <?= csrf_field() ?>
                <input type="hidden" name="to" value="customers">
                <button class="cv-btn cv-btn--secondary" type="submit" title="Opens the reseller's own control panel in a new tab, signed in as the reseller">Log in to reseller account &#8599;</button>
            </form>
        </div>
    </div>
    <nav class="rs-nav" aria-label="This reseller" style="margin:var(--cv-space-4) 0 0;max-width:none;">
        <ul class="rs-nav__list">
            <?php foreach ($tabs as $key => [$href, $label, $paths]): ?>
                <li><a class="rs-nav__link<?= $current === $key ? ' rs-nav__link--active' : '' ?>" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= $svg($paths) ?><span><?= e($label) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
</div>
