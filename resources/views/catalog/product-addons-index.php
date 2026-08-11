<?php
/** @var array<int, array<string, mixed>> $rows */
/** @var array<int, array<string, mixed>> $products */
/** @var int $selectedParentId */
/** @var array<int, array<string, mixed>> $current */
/** @var string|null $error */
/** @var string|null $message */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Product Add-ons</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Configure which products can be sold as <strong>recurring add-ons</strong> to a service whose parent product is the one you pick.
        Add-ons bill on the parent service's billing cycle; the add-on product's own pricing supplies the price. Clients order them from their service page.
    </p>
    <p><a href="/admin/products">&larr; Back to products</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-3);"><div class="cv-field-error"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if (!empty($message)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-3);"><div style="color:var(--cv-color-success-600);font-weight:600;"><?= e($message) ?></div></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Link an add-on to a parent product</h2>
    <form method="post" action="/admin/products/addons" style="display:flex;flex-wrap:wrap;gap:var(--cv-space-3);align-items:flex-end;">
        <?= csrf_field() ?>
        <div class="cv-field" style="flex:1;min-width:200px;">
            <label class="cv-label">Parent product</label>
            <select class="cv-select" name="parent_product_id" required>
                <option value="">Select a parent product</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field" style="flex:1;min-width:200px;">
            <label class="cv-label">Add-on product</label>
            <select class="cv-select" name="addon_product_id" required>
                <option value="">Select an add-on product</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Pin cycle (optional)</label>
            <select class="cv-select" name="billing_cycle">
                <option value="">Any cycle</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semi_annually">Semi-annually</option>
                <option value="annually">Annually</option>
                <option value="biennially">Biennially</option>
                <option value="triennially">Triennially</option>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Sort order</label>
            <input class="cv-input" type="number" name="sort_order" value="0" min="0" style="width:90px;">
        </div>
        <button type="submit" class="cv-btn" style="background:var(--cv-color-brand-500);color:#fff;">Link Add-on</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">All configured add-ons</h2>
    <?php if ($rows === []): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No add-ons configured yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
                <tr>
                    <th>Parent product</th>
                    <th>Add-on product</th>
                    <th>Cycle</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['parent_product_name']) ?></td>
                        <td><?= e($row['addon_name']) ?></td>
                        <td><?= $row['billing_cycle'] === null ? 'Any' : e(ucfirst((string) $row['billing_cycle'])) ?></td>
                        <td><?= e($row['status'] === 'active' ? 'Active' : 'Hidden') ?></td>
                        <td style="text-align:right;">
                            <form method="post" action="/admin/products/addons/<?= (int) $row['id'] ?>/delete" style="margin:0;" data-confirm="Unlink this add-on? Existing add-on services are not affected.">
                                <?= csrf_field() ?>
                                <button type="submit" style="background:none;border:none;color:var(--cv-color-danger);cursor:pointer;font-weight:600;">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
