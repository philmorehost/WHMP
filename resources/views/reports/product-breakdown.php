<?php
/** @var array<int, array{product_name: string, quantity: mixed, revenue: float, currency_symbol: string, currency_code: string}> $products */
/** @var array<int, array{currency_symbol: string, currency_code: string, amount: float}> $totals */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Product Breakdown</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Initial-order revenue from <strong>accepted</strong> orders only — pending orders aren't revenue yet and
        fraud-flagged orders never were. Recurring renewal revenue isn't broken down by product here.
    </p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Product</th><th>Currency</th><th>Quantity Sold</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= e($product['product_name']) ?></td>
                <td><?= e($product['currency_code']) ?></td>
                <td><?= (int) $product['quantity'] ?></td>
                <td><?= e($product['currency_symbol'] . number_format((float) $product['revenue'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No order data yet.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($totals !== []): ?>
            <tfoot>
            <?php foreach ($totals as $total): ?>
                <tr>
                    <td><strong>Total</strong></td>
                    <td><strong><?= e($total['currency_code']) ?></strong></td>
                    <td></td>
                    <td><strong><?= e($total['currency_symbol'] . number_format($total['amount'], 2)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tfoot>
        <?php endif; ?>
    </table>
</div>
