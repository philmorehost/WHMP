<?php
/** @var array<int, array<string, mixed>> $products */
/** @var string $baseUrl */
?>
<style>
.admin-prod-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-prod-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-prod-hero__content {
    position: relative;
    z-index: 1;
}
.admin-prod-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
}
.admin-prod-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-prod-links {
    position: relative;
    z-index: 1;
    display: flex;
    gap: 16px;
    margin-top: 16px;
}
.admin-prod-links a {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
}
.admin-prod-links a:hover {
    color: #60a5fa;
}
.admin-prod-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-prod-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-prod-toolbar > div {
    flex: 1;
}
.admin-prod-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    white-space: nowrap;
}
.admin-prod-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
}
.admin-prod-table-wrapper {
    overflow-x: auto;
}
.admin-prod-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-prod-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-prod-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-prod-table td {
    padding: 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-prod-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-prod-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-prod-badge--hidden {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-prod-link {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .85rem;
    color: var(--cv-text-secondary);
    background: var(--cv-bg-surface-sunken);
    padding: 4px 8px;
    border-radius: 4px;
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-prod-copy-btn {
    background: var(--cv-bg-surface-sunken);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    padding: 4px 12px;
    font-weight: 600;
    font-size: .7rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-prod-copy-btn:hover {
    background: var(--cv-color-brand-500);
    color: white;
    border-color: var(--cv-color-brand-500);
}
.admin-prod-edit-btn {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}
.admin-prod-edit-btn:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-prod-empty {
    padding: 80px 40px;
    text-align: center;
}
.admin-prod-empty__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.admin-prod-empty__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.admin-prod-empty__text {
    color: var(--cv-text-secondary);
    margin: 0;
}
@media (max-width: 768px) {
    .admin-prod-hero {
        padding: 32px 24px;
    }
    .admin-prod-hero__title {
        font-size: 1.5rem;
    }
    .admin-prod-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .admin-prod-toolbar > div {
        width: 100%;
    }
}
</style>

<div class="admin-prod-hero">
    <div class="admin-prod-hero__content">
        <a href="/admin" class="admin-prod-hero__back">← Back to Dashboard</a>
        <h1 class="admin-prod-hero__title">📦 Products & Services</h1>
        <div class="admin-prod-links">
            <a href="/admin/products/groups">📁 Product Groups</a>
            <a href="/admin/configurable-options">⚙️ Configurable Options</a>
        </div>
    </div>
</div>

<div class="admin-prod-card">
    <div style="padding:24px;border-bottom:1px solid var(--cv-border-default);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="button" id="toggle-bulk-update-form" class="cv-btn cv-btn--secondary" style="cursor:pointer;">⚙️ Bulk Update Server Settings</button>
    </div>

    <div id="bulk-update-section" style="display:none;padding:24px;background:var(--cv-bg-surface-sunken);border-bottom:1px solid var(--cv-border-default);">
        <h3 style="margin:0 0 var(--cv-space-3) 0;font-weight:700;">⚙️ Bulk Update Selected Products</h3>
        <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin:0 0 var(--cv-space-2) 0;">Select multiple products below using the checkboxes, choose the settings you wish to apply, and click <strong>Update Selected Products</strong>.</p>

        <form id="bulk-update-form" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:var(--cv-space-3);margin-top:var(--cv-space-3);">
            <?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Assigned Provision Server Group</label>
                <select class="cv-select" name="server_group_id">
                    <option value="">— No Change —</option>
                    <?php if (isset($serverGroups)): ?>
                        <?php foreach ($serverGroups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Billing Automation Type</label>
                <select class="cv-select" name="autosetup">
                    <option value="">— No Change —</option>
                    <option value="order">Automatically setup as soon as order is placed</option>
                    <option value="payment">Automatically setup as soon as payment is received</option>
                    <option value="on_accept">Automatically setup when manually accepted</option>
                    <option value="off">Do not automatically setup this product</option>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Requires a Domain Name</label>
                <select class="cv-select" name="require_domain">
                    <option value="">— No Change —</option>
                    <option value="0">No — Not required</option>
                    <option value="1">Yes — Required</option>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Show as In-Cart Upsell Offer</label>
                <select class="cv-select" name="is_upsell">
                    <option value="">— No Change —</option>
                    <option value="0">No — Do not show as upsell</option>
                    <option value="1">Yes — Show as in-cart upsell offer</option>
                </select>
            </div>
            <div style="display:flex;gap:var(--cv-space-2);align-items:flex-end;grid-column: 1 / -1;margin-top:var(--cv-space-2);">
                <button class="cv-btn" type="submit" id="bulk-submit-btn" disabled>✓ Update Selected Products (<span id="selected-count-label">0</span>)</button>
                <button type="button" id="cancel-bulk-update-btn" class="cv-btn cv-btn--secondary">Cancel</button>
            </div>
        </form>
    </div>

    <div class="admin-prod-toolbar">
        <h2 style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.25rem;color:var(--cv-text-primary);margin:0;">📦 All Products</h2>
        <?= $view->partial('partials.table-search', ['target' => '#products-table', 'placeholder' => 'Search products...']) ?>
        <a class="admin-prod-btn" href="/admin/products/create">➕ Add Product</a>
    </div>

    <?php if ($products === []): ?>
        <div class="admin-prod-empty">
            <div class="admin-prod-empty__icon">📦</div>
            <h2 class="admin-prod-empty__title">No Products Found</h2>
            <p class="admin-prod-empty__text">No products have been created yet. <a href="/admin/products/create" style="color:var(--cv-color-brand-500);text-decoration:underline;">Create one now</a>.</p>
        </div>
    <?php else: ?>
        <div class="admin-prod-table-wrapper">
            <table class="admin-prod-table" id="products-table">
                <thead><tr><th style="width:40px;"><input type="checkbox" id="select-all-products" style="cursor:pointer;"></th><th>Name</th><th>Group</th><th>Status</th><th>Stock</th><th>Order Link</th><th style="width:80px;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <?php $orderLink = $baseUrl . '/store/' . (int) $product['id']; ?>
                    <tr>
                        <td style="padding:16px 8px;"><input type="checkbox" class="product-select-checkbox" value="<?= (int) $product['id'] ?>" style="cursor:pointer;"></td>
                        <td><strong><?= e($product['name']) ?></strong></td>
                        <td><?= e($product['group_name']) ?></td>
                        <td>
                            <?php if ($product['status'] === 'active'): ?>
                                <span class="admin-prod-badge--active">Active</span>
                            <?php else: ?>
                                <span class="admin-prod-badge--hidden">Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $product['stock_quantity'] === null ? '<em>Unlimited</em>' : (int) $product['stock_quantity'] ?></td>
                        <td style="display:flex;align-items:center;gap:8px;">
                            <code id="order-link-<?= (int) $product['id'] ?>" class="admin-prod-link"><?= e($orderLink) ?></code>
                            <button type="button" class="admin-prod-copy-btn" data-copy-target="#order-link-<?= (int) $product['id'] ?>">📋 Copy</button>
                        </td>
                        <td style="display:flex;gap:6px;">
                            <a class="admin-prod-edit-btn" href="/admin/products/<?= (int) $product['id'] ?>/edit">✏️ Edit</a>
                            <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/delete" style="margin:0;display:inline;" data-confirm="Are you sure you want to delete this product? This action cannot be undone.">
                                <?= csrf_field() ?>
                                <button type="submit" class="admin-prod-edit-btn" style="background:linear-gradient(135deg,rgba(239,68,68,.2),rgba(239,68,68,.15));color:#ef4444;border-color:rgba(239,68,68,.3);cursor:pointer;padding:6px 12px;font-weight:600;font-size:.75rem;border-radius:6px;border:1px solid;text-decoration:none;">🗑️ Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
