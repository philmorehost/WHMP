<?php
/**
 * The reseller's customers. Every row is one of THIS store's customers
 * (ResellerClientDirectory::clients filters on clients.reseller_id in the query).
 *
 * @var array<string, mixed> $store
 * @var array{customers: int, services_active: int, services_suspended: int, domains: int, invoices_unpaid: int} $summary
 * @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results
 * @var string $search
 * @var string $filter
 * @var string|null $storeError
 * @var string|null $notice
 * @var string|null $error
 * @var string|null $mode    'reseller' (default: the reseller's own area) or 'admin' (the reseller's page in the admin panel)
 * @var string|null $baseUrl where this list lives; customer pages and actions hang off it
 * @var array<string, mixed>|null $owner  admin mode: the reseller's own user account
 * @var array<int, array<string, mixed>>|null $orders admin mode: orders from this store's customers
 */

$isAdmin = ($mode ?? 'reseller') === 'admin';
$base = (string) ($baseUrl ?? '/client/reseller/clients');

$filters = [
    'all' => 'All customers',
    'active' => 'With active services',
    'suspended' => 'With suspended services',
    'unpaid' => 'With unpaid invoices',
];
$totalPages = max(1, (int) ceil($results['total'] / max(1, $results['perPage'])));
$query = static fn (array $over): string => $base . '?' . http_build_query(array_filter(
    array_merge(['q' => $search, 'filter' => $filter === 'all' ? '' : $filter], $over),
    static fn ($v): bool => $v !== '' && $v !== null && $v !== 1
));
$canAct = $storeError === null;
?>
<?php if ($isAdmin): ?>
    <?= $view->render('partials.reseller-admin-head', ['store' => $store, 'owner' => $owner ?? null, 'current' => 'customers']) ?>
    <p style="color:var(--cv-text-secondary);margin:0 0 var(--cv-space-4);">
        These users registered on this reseller's website, so they belong to <strong>Reseller ID <?= (int) $store['id'] ?></strong>
        and do not appear on the main Clients list. Manage them here, or log in to the reseller account and use its Customers area.
    </p>
<?php else: ?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Your customers</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        Everyone who has an account with <strong><?= e((string) ($store['brand_name'] ?? 'your store')) ?></strong>
        <span class="rs-id-badge" title="Your store's unique ID">Reseller ID <?= (int) $store['id'] ?></span>.
        Open a customer to update their details, suspend or unsuspend their services, look after their domains,
        or sign in to their account to see exactly what they see.
    </p>
</div>
<?php endif; ?>

<?php if ($storeError !== null): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e($storeError) ?></div>
<?php endif; ?>
<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <div class="rs-stats">
        <div class="rs-stat">
            <div class="rs-stat__label">Customers</div>
            <div class="rs-stat__value"><?= (int) $summary['customers'] ?></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__label">Active services</div>
            <div class="rs-stat__value"><?= (int) $summary['services_active'] ?></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__label">Suspended services</div>
            <div class="rs-stat__value"><?= (int) $summary['services_suspended'] ?></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__label">Domains</div>
            <div class="rs-stat__value"><?= (int) $summary['domains'] ?></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__label">Unpaid invoices</div>
            <div class="rs-stat__value"><?= (int) $summary['invoices_unpaid'] ?></div>
        </div>
    </div>
</div>

<div class="cv-card">
    <form method="get" action="<?= e($base) ?>" class="rs-cust-toolbar" role="search">
        <label class="rs-visually-hidden" for="rs-cust-q">Search customers</label>
        <input class="cv-input" id="rs-cust-q" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name, email or company">
        <label class="rs-visually-hidden" for="rs-cust-filter">Show</label>
        <select class="cv-input" id="rs-cust-filter" name="filter">
            <?php foreach ($filters as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="cv-btn" type="submit">Search</button>
        <?php if ($search !== '' || $filter !== 'all'): ?>
            <a class="cv-btn cv-btn--secondary" href="<?= e($base) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($results['data'] === []): ?>
        <div class="rs-cust-empty">
            <?php if ($search !== '' || $filter !== 'all'): ?>
                <h2 class="cv-card__title">No customers match</h2>
                <p>Try a different search, or <a href="<?= e($base) ?>">show everyone</a>.</p>
            <?php else: ?>
                <h2 class="cv-card__title">No customers yet</h2>
                <p>When someone creates an account or orders on your store, they appear here.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="rs-cust-table-wrap">
            <table class="cv-table rs-cust-table">
                <thead>
                    <tr>
                        <th scope="col">User ID</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Services</th>
                        <th scope="col">Domains</th>
                        <th scope="col">Unpaid</th>
                        <th scope="col">Joined</th>
                        <th scope="col"><span class="rs-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results['data'] as $row): ?>
                    <?php $id = (int) $row['id']; ?>
                    <tr>
                        <td data-label="User ID"><span class="rs-id-badge rs-id-badge--user">#<?= $id ?></span></td>
                        <td data-label="Customer">
                            <a class="rs-cust-name" href="<?= e($base) ?>/<?= $id ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></a>
                            <div class="rs-cust-sub"><?= e((string) $row['email']) ?><?= trim((string) ($row['company_name'] ?? '')) !== '' ? ' · ' . e((string) $row['company_name']) : '' ?></div>
                            <?php if ((string) $row['status'] === 'closed'): ?>
                                <span class="cv-badge cv-badge--neutral">Closed</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Services">
                            <?= (int) $row['services_active'] ?> active<?php if ((int) $row['services_suspended'] > 0): ?>,
                                <span class="cv-badge cv-badge--warning"><?= (int) $row['services_suspended'] ?> suspended</span>
                            <?php endif; ?>
                            <div class="rs-cust-sub"><?= (int) $row['services_total'] ?> in total</div>
                        </td>
                        <td data-label="Domains"><?= (int) $row['domains_total'] ?></td>
                        <td data-label="Unpaid">
                            <?php if ((int) $row['invoices_unpaid'] > 0): ?>
                                <span class="cv-badge cv-badge--danger"><?= (int) $row['invoices_unpaid'] ?></span>
                            <?php else: ?>
                                <span class="rs-cust-sub">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Joined"><span class="rs-cust-sub"><?= e(substr((string) $row['created_at'], 0, 10)) ?></span></td>
                        <td class="rs-cust-actions">
                            <a class="cv-btn rs-btn-sm" href="<?= e($base) ?>/<?= $id ?>">Manage</a>
                            <?php if ($canAct && (string) $row['status'] !== 'closed'): ?>
                                <form method="post" action="<?= e($base) ?>/<?= $id ?>/login" target="_blank">
                                    <?= csrf_field() ?>
                                    <button class="cv-btn rs-btn-sm cv-btn--secondary" type="submit" title="<?= $isAdmin ? 'Opens the reseller\'s website in a new tab, signed in as this customer' : 'Opens your store\'s website in a new tab, signed in as this customer' ?>">Log in as customer</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="rs-cust-pager" aria-label="Customer pages">
                <?php if ($results['page'] > 1): ?>
                    <a class="cv-btn cv-btn--secondary rs-btn-sm" href="<?= e($query(['page' => $results['page'] - 1])) ?>">&larr; Previous</a>
                <?php endif; ?>
                <span>Page <?= (int) $results['page'] ?> of <?= $totalPages ?> · <?= (int) $results['total'] ?> customers</span>
                <?php if ($results['page'] < $totalPages): ?>
                    <a class="cv-btn cv-btn--secondary rs-btn-sm" href="<?= e($query(['page' => $results['page'] + 1])) ?>">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
    <?php $orders = $orders ?? []; $pendingOrders = count(array_filter($orders, static fn (array $o): bool => (string) $o['status'] === 'pending')); ?>
    <div class="cv-card" style="margin-top:var(--cv-space-4);" id="orders">
        <h2 class="cv-card__title">Orders from this reseller's customers</h2>
        <p style="color:var(--cv-text-secondary);">
            The platform still fulfils these orders, so pending ones are accepted here rather than from the main Orders list.
            <?php if ($pendingOrders > 0): ?><strong><?= $pendingOrders ?> pending.</strong><?php endif; ?>
        </p>
        <?php if ($orders === []): ?>
            <p class="rs-cust-sub">No orders yet.</p>
        <?php else: ?>
            <div class="rs-cust-table-wrap">
                <table class="cv-table rs-cust-table">
                    <thead><tr><th scope="col">Order</th><th scope="col">User ID</th><th scope="col">Customer</th><th scope="col">Total</th><th scope="col">Status</th><th scope="col">Placed</th><th scope="col"><span class="rs-visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td data-label="Order">#<?= (int) $order['id'] ?></td>
                            <td data-label="User ID"><span class="rs-id-badge rs-id-badge--user">#<?= (int) $order['client_id'] ?></span></td>
                            <td data-label="Customer"><a href="<?= e($base) ?>/<?= (int) $order['client_id'] ?>"><?= e(trim($order['first_name'] . ' ' . $order['last_name'])) ?></a></td>
                            <td data-label="Total"><?= isset($orderMoney) ? e($orderMoney($order)) : e(number_format((float) $order['total'], 2)) ?></td>
                            <td data-label="Status"><span class="cv-badge cv-badge--<?= (string) $order['status'] === 'pending' ? 'warning' : ((string) $order['status'] === 'active' ? 'success' : 'neutral') ?>"><?= e(ucfirst((string) $order['status'])) ?></span></td>
                            <td data-label="Placed"><?= e(substr((string) $order['created_at'], 0, 10)) ?></td>
                            <td data-label=""><a class="cv-btn rs-btn-sm<?= (string) $order['status'] === 'pending' ? '' : ' cv-btn--secondary' ?>" href="/admin/orders/<?= (int) $order['id'] ?>"><?= (string) $order['status'] === 'pending' ? 'Review &amp; accept' : 'View' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
