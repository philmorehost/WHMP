<?php
/** @var array<int, array<string, mixed>> $products */
/** @var string $baseUrl */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Products &amp; Services</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/products/groups">Product Groups</a> &middot; <a href="/admin/configurable-options">Configurable Options</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">Products</h2>
        <?= $view->partial('partials.table-search', ['target' => '#products-table', 'placeholder' => 'Search products...']) ?>
        <a class="cv-btn" href="/admin/products/create">Add Product</a>
    </div>
    <table class="cv-table" id="products-table">
        <thead><tr><th>Name</th><th>Group</th><th>Status</th><th>Stock</th><th>Order Link</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <?php $orderLink = $baseUrl . '/store/' . (int) $product['id']; ?>
            <tr>
                <td><?= e($product['name']) ?></td>
                <td><?= e($product['group_name']) ?></td>
                <td>
                    <?php if ($product['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Hidden</span>
                    <?php endif; ?>
                </td>
                <td><?= $product['stock_quantity'] === null ? 'Unlimited' : (int) $product['stock_quantity'] ?></td>
                <td style="display:flex;align-items:center;gap:var(--cv-space-2);">
                    <code id="order-link-<?= (int) $product['id'] ?>" style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);max-width:14rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($orderLink) ?></code>
                    <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#order-link-<?= (int) $product['id'] ?>">Copy</button>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/products/<?= (int) $product['id'] ?>/edit">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No products yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
