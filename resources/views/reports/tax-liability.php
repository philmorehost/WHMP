<?php
/** @var int $year */
/** @var array<int, array{month: string, tax_amount: mixed}> $byMonth */
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
        <thead><tr><th>Month</th><th>Tax Collected</th></tr></thead>
        <tbody>
        <?php $yearTotal = 0.0; ?>
        <?php foreach ($byMonth as $row): ?>
            <?php $yearTotal += (float) $row['tax_amount']; ?>
            <tr>
                <td><?= e($row['month']) ?></td>
                <td>$<?= number_format((float) $row['tax_amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($byMonth === []): ?>
            <tr><td colspan="2" style="color:var(--cv-text-secondary);">No paid invoices in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($byMonth !== []): ?>
            <tfoot><tr><td><strong>Total</strong></td><td><strong>$<?= number_format($yearTotal, 2) ?></strong></td></tr></tfoot>
        <?php endif; ?>
    </table>
</div>
