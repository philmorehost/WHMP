<?php
/** @var array<string, mixed>|null $product */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, array<string, mixed>> $serverGroups */
/** @var array<string, array<string, mixed>> $pricing */
/** @var array<string, string> $cycles */
/** @var array<int, array<string, mixed>> $optionGroups */
/** @var array<int, int> $attachedOptionGroups */
/** @var string|null $error */
$isEdit = $product !== null;
$action = $isEdit ? "/admin/products/{$product['id']}" : '/admin/products';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Product' : 'Add Product' ?></h1>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Product Group</label>
            <select class="cv-select" name="product_group_id" required>
                <option value="">Select a group</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>" <?= ($product['product_group_id'] ?? null) == $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" value="<?= e((string) ($product['name'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Description</label>
            <textarea class="cv-textarea" name="description" rows="3"><?= e((string) ($product['description'] ?? '')) ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">Status</label>
                <select class="cv-select" name="status">
                    <option value="active" <?= ($product['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="hidden" <?= ($product['status'] ?? '') === 'hidden' ? 'selected' : '' ?>>Hidden</option>
                </select>
            </div>
            <div class="cv-field">
                <label class="cv-label">Stock Quantity (blank = unlimited)</label>
                <input class="cv-input" type="number" name="stock_quantity" value="<?= e((string) ($product['stock_quantity'] ?? '')) ?>">
            </div>
        </div>

        <div class="cv-field">
            <label style="display:flex;align-items:center;gap:var(--cv-space-1);">
                <input type="checkbox" name="is_upsell" value="1" <?= !empty($product['is_upsell']) ? 'checked' : '' ?>>
                Show as an in-cart upsell offer
            </label>
        </div>
        <div class="cv-field">
            <label class="cv-label">Upsell Pitch (shown in cart)</label>
            <input class="cv-input" name="upsell_pitch" value="<?= e((string) ($product['upsell_pitch'] ?? '')) ?>" placeholder="e.g. Protect every page with a wildcard SSL certificate">
        </div>

        <div class="cv-field">
            <label class="cv-label">Server Group (blank = not provisioned)</label>
            <select class="cv-select" name="server_group_id">
                <option value="">None — not auto-provisioned</option>
                <?php foreach ($serverGroups as $sg): ?>
                    <option value="<?= (int) $sg['id'] ?>" <?= ($product['server_group_id'] ?? null) == $sg['id'] ? 'selected' : '' ?>><?= e($sg['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <h3>Pricing</h3>
        <table class="cv-table">
            <thead><tr><th>Enabled</th><th>Cycle</th><th>Setup Fee</th><th>Price</th></tr></thead>
            <tbody>
            <?php foreach ($cycles as $cycleKey => $label): ?>
                <?php $row = $pricing[$cycleKey] ?? null; ?>
                <tr>
                    <td><input type="checkbox" name="cycle_enabled[<?= $cycleKey ?>]" value="1" <?= $row !== null ? 'checked' : '' ?>></td>
                    <td><?= e($label) ?></td>
                    <td><input class="cv-input" type="number" step="0.01" name="setup_fee[<?= $cycleKey ?>]" value="<?= e((string) ($row['setup_fee'] ?? '0.00')) ?>" style="width:8rem;"></td>
                    <td><input class="cv-input" type="number" step="0.01" name="price[<?= $cycleKey ?>]" value="<?= e((string) ($row['price'] ?? '0.00')) ?>" style="width:8rem;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($optionGroups !== []): ?>
            <h3>Configurable Option Groups</h3>
            <?php foreach ($optionGroups as $og): ?>
                <div class="cv-field" style="margin-bottom:var(--cv-space-1);">
                    <label class="cv-label" style="font-weight:normal;">
                        <input type="checkbox" name="option_groups[]" value="<?= (int) $og['id'] ?>" <?= in_array((int) $og['id'], $attachedOptionGroups, true) ? 'checked' : '' ?>>
                        <?= e($og['name']) ?>
                    </label>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-4);"><?= $isEdit ? 'Save Changes' : 'Create Product' ?></button>
    </form>
</div>
