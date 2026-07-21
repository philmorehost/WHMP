<?php
/** @var array<string, mixed> $order */
/** @var array<int, array<string, mixed>> $items */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Order ORD-<?= (int) $order['id'] ?></h1>
    <p><a href="/admin/orders">&larr; Back to orders</a></p>
    <p><strong>Client:</strong> <?= e($order['first_name'] . ' ' . $order['last_name']) ?> (<?= e($order['client_email']) ?>)</p>
    <p><strong>Status:</strong> <?= e($order['status']) ?></p>

    <?php if ($order['status'] === 'fraud'): ?>
        <div class="cv-field-error" style="margin-top:var(--cv-space-3);">
            <p><strong>Held for fraud review</strong> &mdash; score <?= number_format((float) ($order['fraud_score'] ?? 0), 0) ?>/100</p>
            <?php $reasons = json_decode((string) ($order['fraud_reasons'] ?? '[]'), true) ?: []; ?>
            <ul>
                <?php foreach ($reasons as $reason): ?>
                    <li><?= e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif (($order['fraud_score'] ?? null) !== null): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Fraud score: <?= number_format((float) $order['fraud_score'], 0) ?>/100 (below hold threshold)</p>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);">
        <?php if ($order['status'] === 'pending' || $order['status'] === 'fraud'): ?>
            <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/accept"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Accept</button>
            </form>
            <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/cancel"><?= csrf_field() ?>
                <button class="cv-btn cv-btn--danger" type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/delete" data-confirm="Delete order ORD-<?= (int) $order['id'] ?>? This cannot be undone."><?= csrf_field() ?>
            <button class="cv-btn cv-btn--danger" type="submit">Delete Order</button>
        </form>
    </div>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Items</h2>
    <table class="cv-table">
        <thead><tr><th>Product</th><th>Cycle</th><th>Qty</th><th>Setup</th><th>Unit Price</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['product_name']) ?></td>
                <td><?= e($item['billing_cycle']) ?></td>
                <td><?= (int) $item['quantity'] ?></td>
                <td>$<?= number_format((float) $item['setup_fee'], 2) ?></td>
                <td>$<?= number_format((float) $item['unit_price'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
