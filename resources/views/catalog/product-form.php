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
            <label class="cv-label">Product Type</label>
            <select class="cv-select" name="type">
                <option value="shared" <?= ($product['type'] ?? 'other') === 'shared' ? 'selected' : '' ?>>Shared Hosting</option>
                <option value="reseller" <?= ($product['type'] ?? 'other') === 'reseller' ? 'selected' : '' ?>>Reseller Hosting</option>
                <option value="vps" <?= ($product['type'] ?? 'other') === 'vps' ? 'selected' : '' ?>>VPS Server</option>
                <option value="dedicated" <?= ($product['type'] ?? 'other') === 'dedicated' ? 'selected' : '' ?>>Dedicated Server</option>
                <option value="other" <?= ($product['type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other Product/Service</option>
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
            <label style="display:flex;align-items:center;gap:var(--cv-space-1);">
                <input type="checkbox" name="require_domain" value="1" <?= !empty($product['require_domain']) ? 'checked' : '' ?>>
                Requires a domain name (prompt client for registration, transfer, or use existing during checkout)
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

        <div class="cv-field">
            <label class="cv-label">WHM / cPanel Package Name</label>
            <input class="cv-input" name="whm_package_name" value="<?= e((string) ($product['whm_package_name'] ?? '')) ?>" placeholder="e.g. cpanel_gold or username_packagename">
            <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Exact WHM Package name as created in cPanel/WHM. If left blank, WHM default package will be used.</span>
        </div>

        <div style="margin-bottom:var(--cv-space-4);background:var(--cv-bg-surface, rgba(255,255,255,0.03));padding:var(--cv-space-3);border:1px solid var(--cv-border-color, rgba(255,255,255,0.1));border-radius:6px;color:var(--cv-text-primary, inherit);">
            <div style="display:flex;flex-direction:column;gap:var(--cv-space-2);">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="autosetup" value="order" <?= ($product['autosetup'] ?? 'payment') === 'order' ? 'checked' : '' ?>>
                    Automatically setup the product as soon as an order is placed
                </label>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="autosetup" value="payment" <?= ($product['autosetup'] ?? 'payment') === 'payment' ? 'checked' : '' ?>>
                    Automatically setup the product as soon as the first payment is received
                </label>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="autosetup" value="on_accept" <?= ($product['autosetup'] ?? 'payment') === 'on_accept' ? 'checked' : '' ?>>
                    Automatically setup the product when you manually accept a pending order
                </label>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="autosetup" value="off" <?= ($product['autosetup'] ?? 'payment') === 'off' ? 'checked' : '' ?>>
                    Do not automatically setup this product
                </label>
            </div>
        </div>

        <div class="cv-field">
            <label class="cv-label">Payment Type</label>
            <select class="cv-select" name="pay_type" id="pay_type_select" onchange="togglePayTypeOptions()">
                <option value="paid" <?= ($product['pay_type'] ?? 'paid') === 'paid' ? 'selected' : '' ?>>Paid (Recurring / One-Time Pricing)</option>
                <option value="free" <?= ($product['pay_type'] ?? '') === 'free' ? 'selected' : '' ?>>Free Package</option>
            </select>
        </div>

        <div id="free_hosting_options_container" style="display:<?= ($product['pay_type'] ?? 'paid') === 'free' ? 'block' : 'none' ?>;margin-bottom:var(--cv-space-4);background:rgba(245,158,11,0.08);padding:var(--cv-space-3);border:1px solid rgba(245,158,11,0.3);border-radius:6px;color:var(--cv-text-primary, inherit);">
            <label class="cv-label" style="font-weight:700;margin-bottom:var(--cv-space-2);display:block;color:#f59e0b;">🎁 Free Package / Product Duration Options</label>
            <div style="display:flex;flex-direction:column;gap:var(--cv-space-2);">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="free_duration_type" value="lifetime" <?= ($product['free_duration_type'] ?? 'lifetime') === 'lifetime' ? 'checked' : '' ?>>
                    Free for Life (Unlimited / Lifetime access)
                </label>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;color:inherit;">
                    <input type="radio" name="free_duration_type" value="days" <?= ($product['free_duration_type'] ?? '') === 'days' ? 'checked' : '' ?>>
                    Fixed Duration (Free for specified number of days)
                </label>
                <div style="margin-left:1.75rem;margin-top:var(--cv-space-1);">
                    <label class="cv-label" style="font-size:0.85rem;">Number of Free Days (e.g. 30, 90, 365):</label>
                    <input class="cv-input" type="number" name="free_duration_days" value="<?= e((string) ($product['free_duration_days'] ?? '30')) ?>" min="1" style="max-width:150px;">
                </div>
            </div>
        </div>

        <div id="pricing_table_container" style="display:<?= ($product['pay_type'] ?? 'paid') === 'paid' ? 'block' : 'none' ?>;">
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
        </div>

        <script>
        function togglePayTypeOptions() {
            var val = document.getElementById('pay_type_select').value;
            var freeContainer = document.getElementById('free_hosting_options_container');
            var pricingContainer = document.getElementById('pricing_table_container');
            if (val === 'free') {
                freeContainer.style.display = 'block';
                pricingContainer.style.display = 'none';
            } else {
                freeContainer.style.display = 'none';
                pricingContainer.style.display = 'block';
            }
        }
        </script>

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
