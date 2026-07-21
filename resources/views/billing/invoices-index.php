<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $statusFilter */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Invoices</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/invoices">All</a>
        <a class="cv-btn <?= $statusFilter === 'unpaid' ? '' : 'cv-btn--secondary' ?>" href="/admin/invoices?status=unpaid">Unpaid</a>
        <a class="cv-btn <?= $statusFilter === 'paid' ? '' : 'cv-btn--secondary' ?>" href="/admin/invoices?status=paid">Paid</a>
    </div>
</div>

<?php if (!empty($currencyStats)): ?>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:var(--cv-space-4); margin-bottom:var(--cv-space-4);">
        <?php foreach ($currencyStats as $stat): ?>
            <div class="cv-card" style="padding:var(--cv-space-4); border: 1px solid var(--cv-border-default); border-radius: var(--cv-radius-md); box-shadow: var(--cv-shadow-xs);">
                <h3 style="margin-top:0; font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-sm); color:var(--cv-text-secondary); text-align: center; border-bottom: 1px solid var(--cv-border-subtle); padding-bottom: var(--cv-space-2); margin-bottom: var(--cv-space-3);"><?= e($stat['currency_code']) ?> (<?= e($stat['currency_symbol']) ?>) Summary</h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:var(--cv-space-2);">
                    <div style="background:rgba(34, 197, 94, 0.1); padding:var(--cv-space-3); border-radius:var(--cv-radius-sm); text-align:center; border: 1px solid rgba(34, 197, 94, 0.2);">
                        <div style="font-size:var(--cv-text-xs); color:#16a34a; font-weight: 700; text-transform: uppercase;">Paid</div>
                        <div style="color:#15803d; font-size:clamp(1rem, 4vw, var(--cv-text-lg)); word-break: break-all; font-weight: 800; font-family:'Hanken Grotesk',sans-serif; margin-top:4px;"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_paid'], 2) ?></div>
                    </div>
                    <div style="background:rgba(239, 68, 68, 0.1); padding:var(--cv-space-3); border-radius:var(--cv-radius-sm); text-align:center; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <div style="font-size:var(--cv-text-xs); color:#dc2626; font-weight: 700; text-transform: uppercase;">Unpaid</div>
                        <div style="color:#b91c1c; font-size:clamp(1rem, 4vw, var(--cv-text-lg)); word-break: break-all; font-weight: 800; font-family:'Hanken Grotesk',sans-serif; margin-top:4px;"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_unpaid'], 2) ?></div>
                    </div>
                    <div style="background:rgba(220, 38, 38, 0.05); padding:var(--cv-space-3); border-radius:var(--cv-radius-sm); text-align:center; border: 1px solid rgba(220, 38, 38, 0.15);">
                        <div style="font-size:var(--cv-text-xs); color:#dc2626; font-weight: 700; text-transform: uppercase;">Overdue</div>
                        <div style="color:#991b1b; font-size:clamp(1rem, 4vw, var(--cv-text-lg)); word-break: break-all; font-weight: 800; font-family:'Hanken Grotesk',sans-serif; margin-top:4px;"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_overdue'], 2) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#invoices-table', 'placeholder' => 'Search invoices...']) ?>
    </div>
    <table class="cv-table" id="invoices-table">
        <thead><tr><th>#</th><th>Client</th><th>Total</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['data'] as $invoice): ?>
            <tr>
                <td><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                <td><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['client_email']) ?>)</td>
                <td><?= e($invoice['currency_symbol'] ?? '$') ?><?= number_format((float) $invoice['total'] * (float) ($invoice['currency_rate'] ?? 1), 2) ?> <span style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); font-weight: 700;"><?= e($invoice['currency_code'] ?? 'USD') ?></span></td>
                <td><?= e($invoice['due_date']) ?></td>
                <td>
                    <?php if ($invoice['status'] === 'paid'): ?>
                        <span class="cv-badge cv-badge--success">Paid</span>
                    <?php elseif ($invoice['status'] === 'cancelled'): ?>
                        <span class="cv-badge cv-badge--neutral">Cancelled</span>
                    <?php elseif ($invoice['status'] === 'refunded'): ?>
                        <span class="cv-badge cv-badge--neutral">Refunded</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Unpaid</span>
                    <?php endif; ?>
                </td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/invoices/<?= (int) $invoice['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($results['data'] === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No invoices found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="cv-datatable__pagination">
        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-right:var(--cv-space-2);">Page <?= $results['page'] ?> of <?= $totalPages ?></span>
        <?php if ($results['page'] > 1): ?>
            <a class="cv-btn cv-btn--secondary" href="/admin/invoices?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] - 1 ?>">Previous</a>
        <?php endif; ?>
        <?php if ($results['page'] < $totalPages): ?>
            <a class="cv-btn cv-btn--secondary" href="/admin/invoices?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] + 1 ?>">Next</a>
        <?php endif; ?>
    </div>
</div>
