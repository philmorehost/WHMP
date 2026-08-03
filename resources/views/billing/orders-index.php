<?php
/** @var array<int, array<string, mixed>> $orders */
/** @var string $statusFilter */
?>
<style>
/* Admin Orders List Styles */
.admin-orders-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}
.admin-orders-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-orders-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.admin-orders-hero__back {
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
.admin-orders-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-orders-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}

/* Status Tabs */
.admin-orders-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
}
.admin-orders-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--cv-text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: .9rem;
    white-space: nowrap;
    text-decoration: none;
}
.admin-orders-tab:hover {
    color: var(--cv-text-primary);
}
.admin-orders-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}

/* Toolbar */
.admin-orders-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.admin-orders-toolbar > div {
    flex: 1;
    min-width: 250px;
}

/* Table */
.admin-orders-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-orders-table-wrapper {
    overflow-x: auto;
}
.admin-orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-orders-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-orders-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-orders-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-orders-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-orders-table td {
    padding: 16px;
    color: var(--cv-text-primary);
}
.admin-orders-table a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-orders-table a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Badge */
.admin-orders-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-orders-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-orders-badge--pending {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-orders-badge--cancelled {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}
.admin-orders-badge--fraud {
    background: linear-gradient(135deg, rgba(245,158,11,.2), rgba(217,119,6,.15));
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,.3);
}

/* Buttons */
.admin-orders-btn {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
    white-space: nowrap;
}
.admin-orders-btn:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-orders-btn--danger {
    color: #ef4444;
    border-color: rgba(239,68,68,.3);
}
.admin-orders-btn--danger:hover {
    background: rgba(239,68,68,.1);
    border-color: rgba(239,68,68,.5);
}

/* Empty State */
.admin-orders-empty {
    padding: 80px 40px;
    text-align: center;
}
.admin-orders-empty__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.admin-orders-empty__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.admin-orders-empty__text {
    color: var(--cv-text-secondary);
    margin: 0;
}

@media (max-width: 768px) {
    .admin-orders-hero {
        flex-direction: column;
        padding: 32px 24px;
        align-items: flex-start;
    }
    .admin-orders-hero__title {
        font-size: 1.5rem;
    }
    .admin-orders-toolbar {
        flex-direction: column;
    }
    .admin-orders-toolbar > div {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-orders-hero">
    <div class="admin-orders-hero__content">
        <a href="/admin" class="admin-orders-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-orders-hero__title">📦 Orders</h1>
    </div>
    <a href="/admin/orders/create" class="cv-btn cv-btn--primary" style="white-space:nowrap;">＋ Create Order</a>
</div>

<!-- Status Tabs -->
<div class="admin-orders-tabs">
    <a href="/admin/orders" class="admin-orders-tab <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
    <a href="/admin/orders?status=pending" class="admin-orders-tab <?= $statusFilter === 'pending' ? 'active' : '' ?>">🟡 Pending</a>
    <a href="/admin/orders?status=active" class="admin-orders-tab <?= $statusFilter === 'active' ? 'active' : '' ?>">🟢 Active</a>
    <a href="/admin/orders?status=cancelled" class="admin-orders-tab <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">⚫ Cancelled</a>
    <a href="/admin/orders?status=fraud" class="admin-orders-tab <?= $statusFilter === 'fraud' ? 'active' : '' ?>">🚨 Fraud Review</a>
</div>

<!-- Search Toolbar -->
<div class="admin-orders-toolbar">
    <div>
        <?= $view->partial('partials.table-search', ['target' => '#orders-table', 'placeholder' => 'Search orders by ID, client name, or email...']) ?>
    </div>
</div>

<!-- Orders Table -->
<div class="admin-orders-card">
    <?php if ($orders === []): ?>
        <div class="admin-orders-empty">
            <div class="admin-orders-empty__icon">📦</div>
            <h2 class="admin-orders-empty__title">No Orders Found</h2>
            <p class="admin-orders-empty__text">
                <?= !empty($statusFilter) ? 'No orders match this status filter.' : 'No orders have been created yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="admin-orders-table-wrapper">
            <table class="admin-orders-table" id="orders-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Client</th>
                        <th style="text-align:right;">Total</th>
                        <th>Status</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><a href="/admin/orders/<?= (int) $order['id'] ?>"><strong>ORD-<?= (int) $order['id'] ?></strong></a></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <strong><?= e($order['first_name'] . ' ' . $order['last_name']) ?></strong>
                                <span style="font-size:.8rem;color:var(--cv-text-secondary);"><?= e($order['client_email']) ?></span>
                            </div>
                        </td>
                        <td style="text-align:right;font-family:'Monaco','Courier New',monospace;font-weight:700;">
                            <?= e($order['currency_symbol'] ?? '$') ?><?= number_format((float) $order['total'], 2) ?>
                        </td>
                        <td>
                            <?php if ($order['status'] === 'active'): ?>
                                <span class="admin-orders-badge admin-orders-badge--active">Active</span>
                            <?php elseif ($order['status'] === 'cancelled'): ?>
                                <span class="admin-orders-badge admin-orders-badge--cancelled">Cancelled</span>
                            <?php elseif ($order['status'] === 'fraud'): ?>
                                <span class="admin-orders-badge admin-orders-badge--fraud">Fraud</span>
                            <?php else: ?>
                                <span class="admin-orders-badge admin-orders-badge--pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <a class="admin-orders-btn" href="/admin/orders/<?= (int) $order['id'] ?>">View</a>
                            <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/delete" data-confirm="Delete order ORD-<?= (int) $order['id'] ?>? This cannot be undone." style="display:inline;margin:0;">
                                <?= csrf_field() ?>
                                <button class="admin-orders-btn admin-orders-btn--danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
