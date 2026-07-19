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
                <td>$<?= number_format((float) $invoice['total'], 2) ?></td>
                <td><?= e($invoice['due_date']) ?></td>
                <td>
                    <?php if ($invoice['status'] === 'paid'): ?>
                        <span class="cv-badge cv-badge--success">Paid</span>
                    <?php elseif ($invoice['status'] === 'cancelled'): ?>
                        <span class="cv-badge cv-badge--neutral">Cancelled</span>
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
