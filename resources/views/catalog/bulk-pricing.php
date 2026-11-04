<?php

declare(strict_types=1);

/**
 * Admin → Bulk Price Updates.
 *
 * @var array<int, array<string, mixed>> $groups product groups (categories)
 * @var array<int, array<int, array<string, mixed>>> $productsByGroup group id => products
 * @var array<int, array<string, array<string, mixed>>> $pricingByProduct product id => cycle => pricing row
 * @var array<int, array<string, mixed>> $tldPricing domain_pricing rows
 * @var array<string, string> $cycles cycle key => label
 * @var string|null $error
 * @var array{productsChanged: int, domainsChanged: int, notified: int, noImpact: int, failed: int} $result
 */
$hasResult = $result['productsChanged'] > 0 || $result['domainsChanged'] > 0 || $result['notified'] > 0;
$tldsByCategory = [];
foreach ($tldPricing as $tldRow) {
    $tldsByCategory[(string) ($tldRow['category'] ?? 'Popular')][] = $tldRow;
}
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Bulk Price Updates</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Adjust many prices in one pass: pick <strong>products by their category</strong> and/or
        <strong>domain extensions by TLD</strong>, choose the change, and apply. Every client who
        subscribes to an affected product (at one of the changed billing cycles) or holds a domain
        under an affected extension automatically gets a <strong>price review email</strong> listing
        only the lines that affect them — one email per client even when both apply.
    </p>
    <p style="color:#b45309;font-size:var(--cv-text-xs);">
        ⚠️ This rewrites prices immediately and cannot be undone by the tool — double-check the amounts before applying.
        New prices apply to future invoices/renewals; existing unpaid invoices keep their original amounts.
    </p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-card" style="background:rgba(239,68,68,0.1);border-color:#ef4444;margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e((string) $error) ?></div>
    </div>
<?php endif; ?>

<?php if ($hasResult): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;margin-bottom:var(--cv-space-4);">
        <strong>✅ Bulk update applied.</strong>
        <?= (int) $result['productsChanged'] ?> product price line<?= $result['productsChanged'] === 1 ? '' : 's' ?> and
        <?= (int) $result['domainsChanged'] ?> TLD price field<?= $result['domainsChanged'] === 1 ? '' : 's' ?> changed.
        <?= (int) $result['notified'] ?> price review email<?= $result['notified'] === 1 ? '' : 's' ?> queued to subscribers
        <?php if ($result['noImpact'] > 0): ?>
            (<?= (int) $result['noImpact'] ?> subscriber<?= $result['noImpact'] === 1 ? '' : 's' ?> had no changed cycle/extension and were not emailed)
        <?php endif; ?>
        — delivery is queued like every outbound email; check <a href="/admin/email-log">Email Log</a> per client if one looks stuck.
        <?php if ($result['failed'] > 0): ?>
            <div style="color:#ef4444;margin-top:6px;"><?= (int) $result['failed'] ?> notice<?= $result['failed'] === 1 ? '' : 's' ?> could not be queued — check the activity log.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="/admin/bulk-pricing" id="bulk-price-form"><?= csrf_field() ?>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">1 · Products — select by category</h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
            Ticking a category selects every product in it; untick individual products to narrow the change.
            Prices shown are the current base-currency prices.
        </p>
        <?php if ($groups === []): ?>
            <p style="color:var(--cv-text-secondary);">No product groups yet — <a href="/admin/products/groups">create one</a> first.</p>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--cv-space-3);">
            <?php foreach ($groups as $group): ?>
                <?php $groupProducts = $productsByGroup[(int) $group['id']] ?? []; ?>
                <div class="bulk-group" style="border:1px solid var(--cv-border-color, rgba(127,127,127,0.25));border-radius:8px;padding:var(--cv-space-2) var(--cv-space-3);">
                    <label style="display:flex;gap:8px;align-items:center;font-weight:700;cursor:pointer;">
                        <input type="checkbox" class="group-check" data-group="group-<?= (int) $group['id'] ?>" value="<?= (int) $group['id'] ?>">
                        <?= e((string) $group['name']) ?>
                        <span style="font-weight:400;color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">(<?= count($groupProducts) ?> product<?= count($groupProducts) === 1 ? '' : 's' ?>)</span>
                    </label>
                    <div id="group-<?= (int) $group['id'] ?>" style="margin-top:6px;display:flex;flex-direction:column;gap:4px;">
                        <?php foreach ($groupProducts as $product): ?>
                            <?php
                            $rows = $pricingByProduct[(int) $product['id']] ?? [];
                            $priceSummary = [];
                            foreach ($rows as $cycleKey => $row) {
                                $priceSummary[] = substr((string) ($cycles[$cycleKey] ?? $cycleKey), 0, 3) . ' ' . number_format((float) $row['price'], 2);
                            }
                            ?>
                            <label style="display:flex;gap:8px;align-items:baseline;cursor:pointer;font-size:var(--cv-text-sm);">
                                <input type="checkbox" name="products[]" value="<?= (int) $product['id'] ?>" class="product-check group-<?= (int) $group['id'] ?>">
                                <span>
                                    <?= e((string) $product['name']) ?>
                                    <?php if (($product['status'] ?? '') !== 'active'): ?><span style="color:var(--cv-text-secondary);">(hidden)</span><?php endif; ?>
                                    <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);"><?= $priceSummary === [] ? 'no prices yet' : e(implode(' · ', $priceSummary)) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ($groupProducts === []): ?>
                            <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">No products in this group.</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-4);flex-wrap:wrap;align-items:flex-end;">
            <div class="cv-field" style="margin:0;">
                <label class="cv-label">Billing cycles to change</label>
                <div style="display:flex;gap:var(--cv-space-3);flex-wrap:wrap;">
                    <?php foreach ($cycles as $cycleKey => $cycleLabel): ?>
                        <label style="display:flex;gap:5px;align-items:center;cursor:pointer;font-size:var(--cv-text-sm);">
                            <input type="checkbox" name="cycles[]" value="<?= e($cycleKey) ?>" checked> <?= e($cycleLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label style="display:flex;gap:6px;align-items:center;cursor:pointer;font-size:var(--cv-text-sm);">
                <input type="checkbox" name="include_setup" value="1"> Also adjust setup fees
            </label>
        </div>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin:var(--cv-space-2) 0 0 0;">
            Only cycles a product already has a price for are touched — a cycle without a price row isn't offered for that product and won't be created here.
        </p>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">2 · Domains — select by TLD</h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
            Tick the extensions to reprice. Current register / renew prices shown.
        </p>
        <?php if ($tldPricing === []): ?>
            <p style="color:var(--cv-text-secondary);">No TLDs configured yet — <a href="/admin/domain-pricing">add pricing</a> first.</p>
        <?php endif; ?>
        <?php foreach ($tldsByCategory as $category => $categoryTlds): ?>
            <div style="margin-bottom:var(--cv-space-3);">
                <label style="display:flex;gap:8px;align-items:center;font-weight:700;cursor:pointer;">
                    <input type="checkbox" class="group-check" data-group="tlds-<?= e(md5((string) $category)) ?>">
                    <?= e($category) ?>
                    <span style="font-weight:400;color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">(<?= count($categoryTlds) ?> extension<?= count($categoryTlds) === 1 ? '' : 's' ?>)</span>
                </label>
                <div id="tlds-<?= e(md5((string) $category)) ?>" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:4px var(--cv-space-3);margin-top:6px;">
                    <?php foreach ($categoryTlds as $tldRow): ?>
                        <label style="display:flex;gap:8px;align-items:baseline;cursor:pointer;font-size:var(--cv-text-sm);">
                            <input type="checkbox" name="tlds[]" value="<?= e((string) $tldRow['tld']) ?>" class="product-check tlds-<?= e(md5((string) $category)) ?>">
                            <span>
                                <strong><?= e((string) $tldRow['tld']) ?></strong>
                                <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
                                    reg <?= number_format((float) $tldRow['register_price'], 2) ?> · renew <?= number_format((float) $tldRow['renew_price'], 2) ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="cv-field" style="margin-top:var(--cv-space-2);">
            <label class="cv-label">Domain price fields to change</label>
            <div style="display:flex;gap:var(--cv-space-3);flex-wrap:wrap;">
                <?php foreach (['register_price' => 'Register', 'transfer_price' => 'Transfer', 'renew_price' => 'Renew'] as $fieldKey => $fieldLabel): ?>
                    <label style="display:flex;gap:5px;align-items:center;cursor:pointer;font-size:var(--cv-text-sm);">
                        <input type="checkbox" name="domain_fields[]" value="<?= e($fieldKey) ?>" checked> <?= e($fieldLabel) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">3 · The change</h2>
        <div style="display:flex;gap:var(--cv-space-3);flex-wrap:wrap;align-items:flex-end;">
            <div class="cv-field" style="margin:0;">
                <label class="cv-label">Adjustment</label>
                <select class="cv-select" name="mode">
                    <option value="percent">Percentage (%)</option>
                    <option value="fixed">Fixed amount</option>
                </select>
            </div>
            <div class="cv-field" style="margin:0;">
                <label class="cv-label">Direction</label>
                <select class="cv-select" name="direction">
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
            </div>
            <div class="cv-field" style="margin:0;">
                <label class="cv-label">Amount</label>
                <input class="cv-input" type="number" name="amount" step="0.01" min="0.01" placeholder="e.g. 10" required style="width:120px;">
            </div>
        </div>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin:var(--cv-space-2) 0 0 0;">
            Percentage adjusts each price relative to its current value (10% increase turns ₦1,000 into ₦1,100);
            a fixed amount adds/subtracts the same figure from every selected price. Prices never go below zero.
        </p>
    </div>

    <div class="cv-card">
        <button class="cv-btn" type="submit" onclick="return confirm('Apply this price change to every selected product/TLD AND queue price review emails to the subscribed clients? This cannot be undone here.')">Apply Bulk Price Update &amp; Notify Subscribers</button>
    </div>
</form>

<script>
// Category header checkbox ticks/unticks every product (and every TLD group
// header) inside it — the same behaviour as the campaign audience picker.
document.querySelectorAll('.group-check').forEach(function (header) {
    header.addEventListener('change', function () {
        document.querySelectorAll('#' + header.dataset.group + ' .product-check').forEach(function (box) {
            box.checked = header.checked;
        });
    });
    // Keep the header honest when a nested box is toggled by hand.
    var nested = document.getElementById(header.dataset.group);
    if (nested) {
        nested.addEventListener('change', function () {
            var boxes = nested.querySelectorAll('.product-check');
            var checked = nested.querySelectorAll('.product-check:checked');
            header.checked = boxes.length > 0 && boxes.length === checked.length;
        });
    }
});
</script>
