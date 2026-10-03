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
 */

$current = $current ?? '';
$ownerId = (int) $store['client_id'];
$brand = trim((string) ($store['brand_name'] ?? '')) !== '' ? (string) $store['brand_name'] : (string) $store['slug'];
$ownerName = is_array($owner ?? null) ? trim((string) $owner['first_name'] . ' ' . (string) $owner['last_name']) : '';
$tabs = [
    'store' => ['/admin/resellers/' . $ownerId . '/store', 'Store'],
    'customers' => ['/admin/resellers/' . $ownerId . '/customers', 'Customers'],
    'account' => ['/admin/resellers/' . $ownerId . '/account', 'Account & earnings'],
];
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<div class="cv-card rs-admin-head" style="margin-bottom:var(--cv-space-4);">
    <p class="rs-cust-back"><a href="/admin/resellers">&larr; All resellers</a></p>
    <div class="rs-admin-head__row">
        <div>
            <h1 class="rs-head__title" style="margin:0;"><?= e($brand) ?></h1>
            <p class="rs-admin-head__ids">
                <span class="rs-id-badge" title="This reseller's unique ID">Reseller ID <?= (int) $store['id'] ?></span>
                <span class="rs-id-badge rs-id-badge--user" title="The reseller's own user account">User ID <?= $ownerId ?></span>
                <?php if ($ownerName !== ''): ?><span class="rs-cust-sub"><?= e($ownerName) ?><?= !empty($owner['email']) ? ' · ' . e((string) $owner['email']) : '' ?></span><?php endif; ?>
                <?php if (($store['status'] ?? 'active') !== 'active'): ?><span class="cv-badge cv-badge--danger">Store suspended</span><?php endif; ?>
            </p>
        </div>
        <div class="rs-cust-hero__actions">
            <form method="post" action="/admin/resellers/<?= $ownerId ?>/login" target="_blank">
                <?= csrf_field() ?>
                <input type="hidden" name="to" value="customers">
                <button class="cv-btn cv-btn--secondary" type="submit" title="Opens the reseller's own control panel in a new tab, signed in as the reseller">Log in to reseller account &#8599;</button>
            </form>
        </div>
    </div>
    <nav class="rs-nav" aria-label="This reseller" style="margin:var(--cv-space-3) 0 0;max-width:none;">
        <ul class="rs-nav__list">
            <?php foreach ($tabs as $key => [$href, $label]): ?>
                <li><a class="rs-nav__link<?= $current === $key ? ' rs-nav__link--active' : '' ?>" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
</div>
