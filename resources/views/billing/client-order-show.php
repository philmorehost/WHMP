<?php
/** @var array<string, mixed> $order */
/** @var array<int, array<string, mixed>> $items */
/** @var bool $cancellable */
/** @var string|null $notice */
/** @var string|null $error */
$symbol = (string) ($order['currency_symbol'] ?? '$');
$statusLabel = (string) ($order['status'] ?? '');
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Order #<?= (int) $order['id'] ?></h1>
    <p><a href="/client/orders">&larr; Back to My Orders</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0;">
        Placed <?= e((string) ($order['created_at'] ?? '')) ?> · Status: <strong><?= e(ucfirst($statusLabel)) ?></strong>
    </p>
</div>

<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e($notice) ?></div>
<?php endif; ?>
<?php if ($error === 'reason_required'): ?>
    <div class="cv-alert" style="margin-bottom:var(--cv-space-4);background:rgba(239,68,68,.1);color:#ef4444;padding:12px 16px;border-radius:8px;">Please provide a reason for cancellation.</div>
<?php elseif ($error === 'cannot_cancel'): ?>
    <div class="cv-alert" style="margin-bottom:var(--cv-space-4);background:rgba(239,68,68,.1);color:#ef4444;padding:12px 16px;border-radius:8px;">This order cannot be cancelled.</div>
<?php endif; ?>

<div class="cv-card" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="cv-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Billing Cycle</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e((string) ($item['product_name'] ?? 'Item')) ?></td>
                    <td style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);"><?= e((string) ($item['billing_cycle'] ?? '-')) ?></td>
                    <td><?= e($symbol) ?><?= number_format((float) ($item['unit_price'] ?? ($item['price'] ?? 0)), 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align:right;">Total</th>
                    <th><?= e($symbol) ?><?= number_format((float) ($order['total'] ?? 0), 2) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($cancellable): ?>
    <div class="cv-card" style="margin-top:var(--cv-space-4);">
        <h2 class="cv-card__title" style="color:#ef4444;">Cancel this order</h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0 0 var(--cv-space-3);">
            If you no longer want this order, you can cancel it. The unpaid invoice raised for this order will be cancelled too.
        </p>
        <?= $view->partial('partials.cancel-order-modal', ['order' => $order]) ?>
    </div>
<?php endif; ?>
