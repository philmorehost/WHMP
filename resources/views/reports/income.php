<?php
/** @var int $year */
/** @var array<int, array{month: string, total: float, currency_symbol: string, currency_code: string}> $byMonth */
/** @var array<int, array{gateway_slug: string, total: float, currency_symbol: string, currency_code: string}> $byGateway */
/** @var array<int, array{currency_symbol: string, currency_code: string, amount: float}> $monthTotals */
/** @var array<int, array{currency_symbol: string, currency_code: string, amount: float}> $gatewayTotals */

// Rows are grouped per currency, so each one renders with its OWN symbol —
// this used to hardcode "$" against every figure regardless of what the
// install actually bills in.
$money = static fn (array $row, string $key): string => $row['currency_symbol'] . number_format((float) $row[$key], 2);
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Income Summary — <?= (int) $year ?></h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
    <form method="get" action="/admin/reports/income" style="margin-top:var(--cv-space-2);">
        <input class="cv-input" type="number" name="year" value="<?= (int) $year ?>" style="width:8rem;display:inline-block;">
        <button class="cv-btn cv-btn--secondary" type="submit">View Year</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">By Month</h2>
    <table class="cv-table">
        <thead><tr><th>Month</th><th>Currency</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($byMonth as $row): ?>
            <tr>
                <td><?= e($row['month']) ?></td>
                <td><?= e($row['currency_code']) ?></td>
                <td><?= e($money($row, 'total')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($byMonth === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No paid invoices in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($monthTotals !== []): ?>
            <tfoot>
            <?php // One total per currency. A single combined number would be adding naira to dollars. ?>
            <?php foreach ($monthTotals as $total): ?>
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

<div class="cv-card">
    <h2 class="cv-card__title">By Gateway</h2>
    <table class="cv-table">
        <thead><tr><th>Gateway</th><th>Currency</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($byGateway as $row): ?>
            <tr>
                <td><?= e($row['gateway_slug']) ?></td>
                <td><?= e($row['currency_code']) ?></td>
                <td><?= e($money($row, 'total')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($byGateway === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No completed transactions in <?= (int) $year ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($gatewayTotals !== []): ?>
            <tfoot>
            <?php foreach ($gatewayTotals as $total): ?>
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
