<?php
/** @var array<int, array<string, mixed>> $promotions */
/** @var CodeVault\View $view */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Promotions</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#promotions-table', 'placeholder' => 'Search promotions...']) ?>
    </div>
    <table class="cv-table" id="promotions-table">
        <thead><tr><th>Code</th><th>Discount</th><th>Redemptions</th><th>Min Order</th><th>Window</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($promotions as $promotion): ?>
            <tr>
                <td><code><?= e($promotion['code']) ?></code></td>
                <td>
                    <?= $promotion['type'] === 'percentage'
                        ? number_format((float) $promotion['value'], 2) . '%'
                        : '$' . number_format((float) $promotion['value'], 2) ?>
                </td>
                <td>
                    <?= (int) $promotion['redemption_count'] ?>
                    <?= $promotion['max_redemptions'] !== null ? ' / ' . (int) $promotion['max_redemptions'] : '' ?>
                </td>
                <td><?= (float) $promotion['min_order_amount'] > 0 ? '$' . number_format((float) $promotion['min_order_amount'], 2) : '&mdash;' ?></td>
                <td>
                    <?= $promotion['starts_at'] !== null ? e((string) $promotion['starts_at']) : 'any time' ?>
                    &rarr;
                    <?= $promotion['expires_at'] !== null ? e((string) $promotion['expires_at']) : 'never' ?>
                </td>
                <td>
                    <?php if ($promotion['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/admin/promotions/<?= (int) $promotion['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($promotions === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No promotions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add / Update Promotion</h3>
    <form method="post" action="/admin/promotions" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Code</label>
            <input class="cv-input" name="code" placeholder="SUMMER20" required style="width:10rem;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Type</label>
            <select class="cv-select" name="type">
                <option value="percentage">Percentage</option>
                <option value="fixed">Fixed amount</option>
            </select>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Value</label>
            <input class="cv-input" type="number" step="0.01" name="value" style="width:7rem;" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Min Order Amount</label>
            <input class="cv-input" type="number" step="0.01" name="min_order_amount" value="0" style="width:8rem;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Max Redemptions (blank = unlimited)</label>
            <input class="cv-input" type="number" name="max_redemptions" style="width:8rem;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Starts (blank = immediately)</label>
            <input class="cv-input" type="date" name="starts_at">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Expires (blank = never)</label>
            <input class="cv-input" type="date" name="expires_at">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Status</label>
            <select class="cv-select" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
        Saving a code that already exists updates it in place rather than creating a duplicate.
    </p>
</div>
