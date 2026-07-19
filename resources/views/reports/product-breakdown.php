<?php
/** @var array<int, array{product_name: string, quantity: mixed, revenue: mixed}> $products */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Product Breakdown</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Initial-order revenue only — recurring renewal revenue isn't broken down by product here.</p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Product</th><th>Quantity Sold</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= e($product['product_name']) ?></td>
                <td><?= (int) $product['quantity'] ?></td>
                <td>$<?= number_format((float) $product['revenue'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No order data yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
