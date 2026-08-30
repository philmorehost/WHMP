<?php
/** @var array<int, array<string, mixed>> $orders */
$statusBadge = static function (string $s): string {
    return match ($s) {
        'active' => '<span class="cv-badge cv-badge--success">Active</span>',
        'pending' => '<span class="cv-badge" style="background:rgba(245,158,11,.16);color:#d97706;">Pending</span>',
        'cancelled' => '<span class="cv-badge cv-badge--danger">Cancelled</span>',
        'fraud' => '<span class="cv-badge" style="background:rgba(239,68,68,.16);color:#ef4444;">Fraud Review</span>',
        default => '<span class="cv-badge cv-badge--neutral">' . e($s) . '</span>',
    };
};
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">My Orders</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0;">
        Your pending and completed orders. A pending order can be cancelled from its detail page — the invoice it raised is cancelled at the same time.
    </p>
</div>

<?php if ($orders === []): ?>
    <div class="cv-card" style="padding:40px;text-align:center;">
        <p style="color:var(--cv-text-secondary);margin:0;">You have no orders yet.</p>
        <p style="margin-top:12px;"><a class="cv-btn" href="/store" style="text-decoration:none;">Browse the Store →</a></p>
    </div>
<?php else: ?>
    <div class="cv-card" style="padding:0;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="cv-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><strong>#<?= (int) $order['id'] ?></strong></td>
                        <td style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);"><?= e((string) ($order['created_at'] ?? '')) ?></td>
                        <td style="font-size:var(--cv-text-sm);"><?= count($order['items'] ?? []) ?> item<?= count($order['items'] ?? []) !== 1 ? 's' : '' ?></td>
                        <td><?= e((string) ($order['currency_symbol'] ?? '$')) ?><?= number_format((float) ($order['total'] ?? 0), 2) ?></td>
                        <td><?= $statusBadge((string) ($order['status'] ?? '')) ?></td>
                        <td><a class="cv-btn" href="/client/orders/<?= (int) $order['id'] ?>" style="text-decoration:none;padding:6px 12px;font-size:.75rem;">View →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
