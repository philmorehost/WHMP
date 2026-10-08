<?php
/**
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $rows
 * @var string $status
 * @var string $q
 * @var int $pageNo
 * @var bool $connected
 * @var string $accountName
 * @var int $productCount
 */
$filters = ['live' => 'All live', 'active' => 'Active', 'pending' => 'Waiting for nameservers', 'paused' => 'Paused', 'deleting' => 'Scheduled for removal', 'deleted' => 'Deleted', 'all' => 'Everything'];
$hasMore = count($rows) > 50;
$rows = array_slice($rows, 0, 50);

$pill = static function (array $z): string {
    if ($z['status'] === 'deleted') {
        return '<span class="cfa-pill cfa-pill--deleted">Deleted</span>';
    }

    if ($z['delete_after'] !== null) {
        return '<span class="cfa-pill cfa-pill--deleting">Removal ' . e(substr((string) $z['delete_after'], 0, 10)) . '</span>';
    }

    if ((int) $z['paused'] === 1) {
        return '<span class="cfa-pill cfa-pill--paused">Paused</span>';
    }

    return $z['status'] === 'active'
        ? '<span class="cfa-pill cfa-pill--active">Active</span>'
        : '<span class="cfa-pill cfa-pill--pending">' . e(ucfirst((string) $z['status'])) . '</span>';
};
?>
<div class="cfa">
    <div class="cfa-head">
        <div>
            <h1><span class="cfa-logo" aria-hidden="true">CF</span> Cloudflare CDN &amp; Security</h1>
            <p>Free-plan zones for your customers and every reseller store's customers, all in
                <?= $connected ? '<strong>' . e($accountName) . '</strong>' : 'your Cloudflare account' ?>.</p>
        </div>
        <a class="cv-btn cv-btn--secondary" href="/admin/cloudflare/settings">Settings</a>
    </div>
    <?= $view->render('cloudflare.admin-tabs', ['active' => 'zones', 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <?php if (!$connected || $productCount === 0): ?>
        <div class="cfa-card">
            <h2>Finish setting up</h2>
            <ol class="cfa-steps">
                <li><?= $connected ? '&#10003; ' : '' ?>Connect your Cloudflare account with an API token — <a href="/admin/cloudflare/settings">Settings</a>.</li>
                <li><?= $productCount > 0 ? '&#10003; ' : '' ?>Choose which products offer free Cloudflare — <a href="/admin/cloudflare/settings#products">Products</a>.</li>
            </ol>
        </div>
    <?php endif; ?>

    <div class="cfa-stats">
        <a class="cfa-stat" style="--cfa-c:#16a34a" href="?status=active"><b><?= (int) $counts['active'] ?></b><span>Active</span></a>
        <a class="cfa-stat" style="--cfa-c:#d97706" href="?status=pending"><b><?= (int) $counts['pending'] ?></b><span>Waiting for nameservers</span></a>
        <a class="cfa-stat" style="--cfa-c:#4f46e5" href="?status=paused"><b><?= (int) $counts['paused'] ?></b><span>Paused (suspended)</span></a>
        <a class="cfa-stat" style="--cfa-c:#dc2626" href="?status=deleting"><b><?= (int) $counts['deleting'] ?></b><span>Scheduled for removal</span></a>
        <div class="cfa-stat" style="--cfa-c:#f6821f"><b><?= (int) $productCount ?></b><span>Products offering it</span></div>
    </div>

    <div class="cfa-card">
        <form class="cfa-filter" method="get" action="/admin/cloudflare">
            <select class="cv-input" name="status" aria-label="Status">
                <?php foreach ($filters as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="cv-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Domain, email or service #" aria-label="Search">
            <button class="cv-btn cv-btn--secondary" type="submit">Filter</button>
        </form>
        <div style="overflow-x:auto">
            <table class="cfa-table">
                <thead><tr><th>Domain</th><th>Customer</th><th>Service</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="cfa-muted" style="text-align:center;padding:24px">No zones match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $z): ?>
                    <?php $isStore = $z['reseller_id'] !== null; ?>
                    <tr>
                        <td><a href="/admin/cloudflare/zones/<?= (int) $z['id'] ?>"><strong><?= e((string) $z['name']) ?></strong></a>
                            <?php if (!empty($z['last_error']) && $z['status'] !== 'deleted'): ?><br><small style="color:#b45309" title="<?= e((string) $z['last_error']) ?>">&#9888; warning</small><?php endif; ?></td>
                        <td>
                            <?= e(trim((string) $z['first_name'] . ' ' . (string) $z['last_name'])) ?: '#' . (int) $z['client_id'] ?>
                            <br><small class="cfa-muted"><?= e((string) $z['client_email']) ?></small>
                            <?php if ($isStore): ?><br><span class="cfa-pill">Store: <?= e((string) ($z['store_name'] ?: $z['store_slug'] ?: '#' . $z['reseller_id'])) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($z['service_id'] !== null): ?>
                                <?php if ($isStore): ?>
                                    #<?= (int) $z['service_id'] ?>
                                <?php else: ?>
                                    <a href="/admin/services/<?= (int) $z['service_id'] ?>">#<?= (int) $z['service_id'] ?></a>
                                <?php endif; ?>
                                <br><small class="cfa-muted"><?= e((string) ($z['product_name'] ?? '')) ?><?= !empty($z['service_status']) ? ' · ' . e((string) $z['service_status']) : '' ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= $pill($z) ?></td>
                        <td><small><?= e(substr((string) $z['created_at'], 0, 10)) ?></small></td>
                        <td><a class="cv-btn cv-btn--secondary" href="/admin/cloudflare/zones/<?= (int) $z['id'] ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pageNo > 1 || $hasMore): ?>
            <div class="cfa-actions" style="margin-top:12px">
                <?php if ($pageNo > 1): ?><a class="cv-btn cv-btn--secondary" href="?<?= e(http_build_query(['status' => $status, 'q' => $q, 'page' => $pageNo - 1])) ?>">&larr; Previous</a><?php endif; ?>
                <?php if ($hasMore): ?><a class="cv-btn cv-btn--secondary" href="?<?= e(http_build_query(['status' => $status, 'q' => $q, 'page' => $pageNo + 1])) ?>">Next &rarr;</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
