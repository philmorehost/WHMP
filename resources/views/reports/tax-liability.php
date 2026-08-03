<?php
/** @var int $year */
/** @var array<int, array{month: string, tax_amount: float, currency_symbol: string, currency_code: string}> $byMonth */
/** @var array<int, array{currency_symbol: string, currency_code: string, amount: float}> $totals */

// Tax is owed in the currency it was collected in, so each row carries its own
// symbol — this used to print "$" against every figure.
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Tax Liability — <?= (int) $year ?></h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
    <form method="get" action="/admin/reports/tax-liability" style="margin-top:var(--cv-space-2);">
        <input class="cv-input" type="number" name="year" value="<?= (int) $year ?>" style="width:8rem;display:inline-block;">
        <button class="cv-btn cv-btn--secondary" type="submit">View Year</button>
    </form>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Month</th><th>Currency</th><th>Tax Collected</th></tr></thead>
        <tbody>
        <?php foreach ($byMonth as $row): ?>
            <tr>
                <td><?= e($row['month']) ?></td>
                <td><?= e($row['currency_code']) ?></td>
                <td><?= e($row['currency_symbol'] . number_format((float) $row['tax_amount'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($byMonth === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No paid invoices in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($totals !== []): ?>
            <tfoot>
            <?php // One liability per currency — a tax authority is owed in its own currency, not a blended total. ?>
            <?php foreach ($totals as $total): ?>
                <tr>
                    <td><strong>Total</strong></td>
                    <td><strong><?= e($total['currency_code']) ?></strong></td>
                    <td><strong><?= e($total['currency_symbol'] . number_format($total['amount'], 2)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tfoot>
        <?php endif; ?>
    </table>
</div>
