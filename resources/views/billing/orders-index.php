<?php
/** @var array<int, array<string, mixed>> $orders */
/** @var string $statusFilter */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Orders</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/orders">All</a>
        <a class="cv-btn <?= $statusFilter === 'pending' ? '' : 'cv-btn--secondary' ?>" href="/admin/orders?status=pending">Pending</a>
        <a class="cv-btn <?= $statusFilter === 'active' ? '' : 'cv-btn--secondary' ?>" href="/admin/orders?status=active">Active</a>
        <a class="cv-btn <?= $statusFilter === 'cancelled' ? '' : 'cv-btn--secondary' ?>" href="/admin/orders?status=cancelled">Cancelled</a>
        <a class="cv-btn <?= $statusFilter === 'fraud' ? '' : 'cv-btn--secondary' ?>" href="/admin/orders?status=fraud">Fraud Review</a>
    </div>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#orders-table', 'placeholder' => 'Search orders...']) ?>
    </div>
    <table class="cv-table" id="orders-table">
        <thead><tr><th>#</th><th>Client</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><a href="/admin/orders/<?= (int) $order['id'] ?>">ORD-<?= (int) $order['id'] ?></a></td>
                <td><?= e($order['first_name'] . ' ' . $order['last_name']) ?> (<?= e($order['client_email']) ?>)</td>
                <td><?= e($order['currency_symbol'] ?? '$') ?><?= number_format((float) $order['total'], 2) ?></td>
                <td>
                    <?php if ($order['status'] === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php elseif ($order['status'] === 'cancelled'): ?>
                        <span class="cv-badge cv-badge--neutral">Cancelled</span>
                    <?php elseif ($order['status'] === 'fraud'): ?>
                        <span class="cv-badge cv-badge--danger">Fraud</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Pending</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <a class="cv-btn cv-btn--secondary" href="/admin/orders/<?= (int) $order['id'] ?>">View</a>
                    <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/delete" data-confirm="Delete order ORD-<?= (int) $order['id'] ?>? This cannot be undone."><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($orders === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No orders yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
