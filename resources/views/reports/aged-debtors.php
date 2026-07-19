<?php
/** @var array<string, array{label: string, invoices: array<int, array<string, mixed>>, total: float}> $buckets */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Aged Debtors</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
</div>

<?php foreach ($buckets as $bucket): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title"><?= e($bucket['label']) ?> &mdash; $<?= number_format($bucket['total'], 2) ?></h2>
        <table class="cv-table">
            <thead><tr><th>Invoice</th><th>Client</th><th>Total</th><th>Due Date</th></tr></thead>
            <tbody>
            <?php foreach ($bucket['invoices'] as $invoice): ?>
                <tr>
                    <td><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                    <td><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['client_email']) ?>)</td>
                    <td>$<?= number_format((float) $invoice['total'], 2) ?></td>
                    <td><?= e($invoice['due_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($bucket['invoices'] === []): ?>
                <tr><td colspan="4" style="color:var(--cv-text-secondary);">None.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
