<?php
/** @var array<string, array{label: string, invoices: array<int, array<string, mixed>>, totals: array<int, array{currency_symbol: string, currency_code: string, amount: float}>}> $buckets */

// Each invoice shows the amount as actually billed (stored total x its own
// locked rate) with its own currency symbol, and each bucket carries one total
// per currency. This used to print a single "$" figure per bucket, summed
// straight across currencies and ignoring currency_rate entirely.
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Aged Debtors</h1>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
</div>

<?php foreach ($buckets as $bucket): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">
            <?= e($bucket['label']) ?>
            <?php if ($bucket['totals'] === []): ?>
                &mdash; <span style="color:var(--cv-text-secondary);font-weight:400;">nothing outstanding</span>
            <?php else: ?>
                &mdash; <?= e(implode('  |  ', array_map(
                    static fn (array $t): string => $t['currency_symbol'] . number_format($t['amount'], 2) . ' ' . $t['currency_code'],
                    $bucket['totals']
                ))) ?>
            <?php endif; ?>
        </h2>
        <table class="cv-table">
            <thead><tr><th>Invoice</th><th>Client</th><th>Amount</th><th>Due Date</th></tr></thead>
            <tbody>
            <?php foreach ($bucket['invoices'] as $invoice): ?>
                <tr>
                    <td><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                    <td><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['client_email']) ?>)</td>
                    <td><?= e($invoice['currency_symbol'] . number_format((float) $invoice['display_amount'], 2)) ?></td>
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
