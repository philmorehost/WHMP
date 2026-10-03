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
 */

$filters = [
    'all' => 'All customers',
    'active' => 'With active services',
    'suspended' => 'With suspended services',
    'unpaid' => 'With unpaid invoices',
];
$totalPages = max(1, (int) ceil($results['total'] / max(1, $results['perPage'])));
$query = static fn (array $over): string => '/client/reseller/clients?' . http_build_query(array_filter(
    array_merge(['q' => $search, 'filter' => $filter === 'all' ? '' : $filter], $over),
    static fn ($v): bool => $v !== '' && $v !== null && $v !== 1
));
$canAct = $storeError === null;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Your customers</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        Everyone who has an account with <strong><?= e((string) ($store['brand_name'] ?? 'your store')) ?></strong>.
        Open a customer to update their details, suspend or unsuspend their services, look after their domains,
        or sign in to their account to see exactly what they see.
    </p>
</div>

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
    <form method="get" action="/client/reseller/clients" class="rs-cust-toolbar" role="search">
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
            <a class="cv-btn cv-btn--secondary" href="/client/reseller/clients">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($results['data'] === []): ?>
        <div class="rs-cust-empty">
            <?php if ($search !== '' || $filter !== 'all'): ?>
                <h2 class="cv-card__title">No customers match</h2>
                <p>Try a different search, or <a href="/client/reseller/clients">show everyone</a>.</p>
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
                        <td data-label="Customer">
                            <a class="rs-cust-name" href="/client/reseller/clients/<?= $id ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></a>
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
                            <a class="cv-btn rs-btn-sm" href="/client/reseller/clients/<?= $id ?>">Manage</a>
                            <?php if ($canAct && (string) $row['status'] !== 'closed'): ?>
                                <form method="post" action="/client/reseller/clients/<?= $id ?>/login" target="_blank">
                                    <?= csrf_field() ?>
                                    <button class="cv-btn rs-btn-sm cv-btn--secondary" type="submit" title="Opens your store's website in a new tab, signed in as this customer">Log in as customer</button>
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
