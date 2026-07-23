<?php
/** @var array<int, array<string, mixed>> $promotions */
/** @var CodeVault\View $view */
?>
<style>
/* Admin Promotions List Styles */
.admin-promo-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-promo-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-promo-hero__content {
    position: relative;
    z-index: 1;
}
.admin-promo-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.admin-promo-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-promo-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}

/* Card */
.admin-promo-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-promo-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-promo-card__body {
    padding: 24px;
}

/* Form Styles */
.admin-promo-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.admin-promo-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-promo-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-promo-field input,
.admin-promo-field select {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
}
.admin-promo-field input:focus,
.admin-promo-field select:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-promo-btn-save {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
    align-self: flex-end;
}
.admin-promo-btn-save:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-promo-hint {
    color: var(--cv-text-secondary);
    font-size: .8rem;
    margin-top: 8px;
}

/* Table */
.admin-promo-table-wrapper {
    overflow-x: auto;
}
.admin-promo-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-promo-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-promo-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-promo-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-promo-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-promo-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-promo-table code {
    background: var(--cv-bg-surface-sunken);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .8rem;
}

/* Badge */
.admin-promo-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-promo-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-promo-badge--inactive {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}

/* Buttons */
.admin-promo-btn-delete {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-promo-btn-delete:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}
.admin-promo-btn-delete-selected {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-promo-btn-delete-selected:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Toolbar */
.admin-promo-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.admin-promo-toolbar > div {
    flex: 1;
    min-width: 200px;
}

/* Empty State */
.admin-promo-empty {
    padding: 60px 40px;
    text-align: center;
}
.admin-promo-empty__text {
    color: var(--cv-text-secondary);
}

@media (max-width: 768px) {
    .admin-promo-hero {
        padding: 32px 24px;
    }
    .admin-promo-hero__title {
        font-size: 1.5rem;
    }
    .admin-promo-form {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-promo-hero">
    <div class="admin-promo-hero__content">
        <a href="/admin" class="admin-promo-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-promo-hero__title">🎟️ Promotions</h1>
    </div>
</div>

<!-- Add / Update Promotion Card -->
<div class="admin-promo-card">
    <h2 class="admin-promo-card__title">➕ Add / Update Promotion</h2>
    <div class="admin-promo-card__body">
        <form method="post" action="/admin/promotions"><?= csrf_field() ?>
            <div class="admin-promo-form">
                <div class="admin-promo-field">
                    <label>Code</label>
                    <input name="code" placeholder="SUMMER20" required>
                </div>
                <div class="admin-promo-field">
                    <label>Type</label>
                    <select name="type">
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed amount</option>
                    </select>
                </div>
                <div class="admin-promo-field">
                    <label>Value</label>
                    <input type="number" step="0.01" name="value" required>
                </div>
                <div class="admin-promo-field">
                    <label>Min Order Amount</label>
                    <input type="number" step="0.01" name="min_order_amount" value="0">
                </div>
                <div class="admin-promo-field">
                    <label>Max Redemptions</label>
                    <input type="number" name="max_redemptions" placeholder="(blank = unlimited)">
                </div>
                <div class="admin-promo-field">
                    <label>Starts</label>
                    <input type="date" name="starts_at" placeholder="(blank = immediately)">
                </div>
                <div class="admin-promo-field">
                    <label>Expires</label>
                    <input type="date" name="expires_at" placeholder="(blank = never)">
                </div>
                <div class="admin-promo-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <button class="admin-promo-btn-save" type="submit">💾 Save Promotion</button>
            <p class="admin-promo-hint">Saving a code that already exists updates it in place rather than creating a duplicate.</p>
        </form>
    </div>
</div>

<!-- Promotions List Card -->
<div class="admin-promo-card">
    <h2 class="admin-promo-card__title">📋 Promotion Codes</h2>
    <div class="admin-promo-card__body" style="padding:0;">
        <form method="post" action="/admin/promotions/delete-multiple" data-confirm="Are you sure you want to delete the selected promotion codes?">
            <?= csrf_field() ?>
            <div class="admin-promo-toolbar" style="padding:16px;">
                <?= $view->partial('partials.table-search', ['target' => '#promotions-table', 'placeholder' => 'Search by code, discount, or status...']) ?>
                <button type="submit" class="admin-promo-btn-delete-selected" id="btn-delete-selected" data-bulk-delete-for=".promo-checkbox" style="display:none;">🗑️ Delete Selected</button>
            </div>

            <?php if ($promotions === []): ?>
                <div class="admin-promo-empty">
                    <p class="admin-promo-empty__text">No promotion codes yet. Create one using the form above.</p>
                </div>
            <?php else: ?>
                <div class="admin-promo-table-wrapper">
                    <table class="admin-promo-table" id="promotions-table">
                        <thead>
                            <tr>
                                <th style="width:2.5rem;text-align:center;">
                                    <input type="checkbox" id="select-all" data-select-all-trigger=".promo-checkbox">
                                </th>
                                <th>Code</th>
                                <th>Discount</th>
                                <th>Redemptions</th>
                                <th>Min Order</th>
                                <th>Window</th>
                                <th>Status</th>
                                <th style="width:70px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($promotions as $promotion): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="ids[]" value="<?= (int) $promotion['id'] ?>" class="promo-checkbox" data-select-all-item=".promo-checkbox">
                                </td>
                                <td><code><?= e($promotion['code']) ?></code></td>
                                <td style="font-weight:700;">
                                    <?= $promotion['type'] === 'percentage'
                                        ? number_format((float) $promotion['value'], 2) . '%'
                                        : '$' . number_format((float) $promotion['value'], 2) ?>
                                </td>
                                <td>
                                    <?= (int) $promotion['redemption_count'] ?>
                                    <?= $promotion['max_redemptions'] !== null ? ' / ' . (int) $promotion['max_redemptions'] : '' ?>
                                </td>
                                <td><?= (float) $promotion['min_order_amount'] > 0 ? '$' . number_format((float) $promotion['min_order_amount'], 2) : '&mdash;' ?></td>
                                <td style="font-size:.85rem;">
                                    <?= $promotion['starts_at'] !== null ? e((string) $promotion['starts_at']) : 'any' ?>
                                    →
                                    <?= $promotion['expires_at'] !== null ? e((string) $promotion['expires_at']) : '∞' ?>
                                </td>
                                <td>
                                    <?php if ($promotion['status'] === 'active'): ?>
                                        <span class="admin-promo-badge admin-promo-badge--active">Active</span>
                                    <?php else: ?>
                                        <span class="admin-promo-badge admin-promo-badge--inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="/admin/promotions/<?= (int) $promotion['id'] ?>/delete" data-confirm="Delete this promotion?" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <button class="admin-promo-btn-delete" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
