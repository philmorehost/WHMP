<?php
/** @var array<string, mixed> $order */
/** @var array<int, array<string, mixed>> $items */
?>
<style>
/* Admin Order Detail Styles */
.admin-order-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-order-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-order-hero__content {
    position: relative;
    z-index: 1;
}
.admin-order-hero__back {
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
.admin-order-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-order-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-order-hero__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    margin-top: 24px;
}
.admin-order-hero__meta-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-order-hero__meta-label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.admin-order-hero__meta-value {
    font-size: .95rem;
    color: white;
    font-weight: 600;
}

/* Fraud Alert */
.admin-order-alert {
    background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(220,38,38,.1));
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 8px;
    padding: 16px;
    margin-top: 24px;
    color: #dc2626;
}
.admin-order-alert__title {
    font-weight: 700;
    margin-bottom: 8px;
}
.admin-order-alert ul {
    margin: 8px 0 0 20px;
    padding: 0;
}
.admin-order-alert li {
    margin: 4px 0;
}

/* Actions */
.admin-order-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.admin-order-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-order-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-order-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-order-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-order-btn--danger:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Order Card */
.admin-order-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    overflow: hidden;
}
.admin-order-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-order-card__body {
    padding: 24px;
}

/* Table */
.admin-order-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-order-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-order-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-order-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-order-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-order-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-order-table td:nth-child(n+4) {
    text-align: right;
    font-family: 'Monaco', 'Courier New', monospace;
    font-weight: 700;
}

@media (max-width: 768px) {
    .admin-order-hero {
        padding: 32px 24px;
    }
    .admin-order-hero__title {
        font-size: 1.5rem;
    }
    .admin-order-hero__meta {
        grid-template-columns: 1fr;
    }
    .admin-order-actions {
        flex-direction: column;
    }
    .admin-order-actions form,
    .admin-order-actions button {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-order-hero">
    <div class="admin-order-hero__content">
        <a href="/admin/orders" class="admin-order-hero__back">
            <span>←</span>
            <span>Back to Orders</span>
        </a>
        <h1 class="admin-order-hero__title">Order ORD-<?= (int) $order['id'] ?></h1>

        <div class="admin-order-hero__meta">
            <div class="admin-order-hero__meta-item">
                <span class="admin-order-hero__meta-label">👤 Client</span>
                <span class="admin-order-hero__meta-value"><?= e($order['first_name'] . ' ' . $order['last_name']) ?></span>
                <span style="font-size:.8rem; color:rgba(255,255,255,.6);"><?= e($order['client_email']) ?></span>
            </div>
            <div class="admin-order-hero__meta-item">
                <span class="admin-order-hero__meta-label">🎯 Status</span>
                <span class="admin-order-hero__meta-value"><?= e($order['status']) ?></span>
            </div>
            <div class="admin-order-hero__meta-item">
                <span class="admin-order-hero__meta-label">💱 Currency</span>
                <span class="admin-order-hero__meta-value"><?= e($order['currency_code'] ?? 'USD') ?> (<?= e($order['currency_symbol'] ?? '$') ?>)</span>
            </div>
            <?php if (($order['fraud_score'] ?? null) !== null): ?>
                <div class="admin-order-hero__meta-item">
                    <span class="admin-order-hero__meta-label">🛡️ Fraud Score</span>
                    <span class="admin-order-hero__meta-value"><?= number_format((float) $order['fraud_score'], 0) ?>/100</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($order['status'] === 'fraud'): ?>
            <div class="admin-order-alert">
                <div class="admin-order-alert__title">⚠️ Held for fraud review</div>
                <?php $reasons = json_decode((string) ($order['fraud_reasons'] ?? '[]'), true) ?: []; ?>
                <ul>
                    <?php foreach ($reasons as $reason): ?>
                        <li><?= e($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="admin-order-actions">
            <?php if ($order['status'] === 'pending' || $order['status'] === 'fraud'): ?>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/accept"><?= csrf_field() ?>
                    <button class="admin-order-btn admin-order-btn--primary" type="submit">✓ Accept Order</button>
                </form>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/cancel"><?= csrf_field() ?>
                    <button class="admin-order-btn admin-order-btn--danger" type="submit">✕ Cancel</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/delete" data-confirm="Delete order ORD-<?= (int) $order['id'] ?>? This cannot be undone."><?= csrf_field() ?>
                <button class="admin-order-btn admin-order-btn--danger" type="submit">🗑️ Delete Order</button>
            </form>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="admin-order-card">
    <h2 class="admin-order-card__title">📦 Order Items</h2>
    <div class="admin-order-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-order-table">
                <thead><tr><th>Product</th><th>Cycle</th><th style="text-align:center;">Qty</th><th>Setup Fee</th><th>Unit Price</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e($item['billing_cycle']) ?></td>
                        <td style="text-align:center;"><?= (int) $item['quantity'] ?></td>
                        <td><?= e($order['currency_symbol'] ?? '$') ?><?= number_format((float) $item['setup_fee'], 2) ?></td>
                        <td><?= e($order['currency_symbol'] ?? '$') ?><?= number_format((float) $item['unit_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
