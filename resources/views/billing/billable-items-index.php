<?php
/** @var array<int, array<string, mixed>> $items */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Billable Items</h1>
    <p><a href="/admin/invoices">&larr; Back to invoices</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#billable-items-table', 'placeholder' => 'Search billable items...']) ?>
    </div>
    <table class="cv-table" id="billable-items-table">
        <thead><tr><th>Client</th><th>Description</th><th>Amount</th><th>Source</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['first_name'] . ' ' . $item['last_name']) ?> (<?= e($item['client_email']) ?>)</td>
                <td><?= e($item['description']) ?></td>
                <td><?= number_format((float) $item['amount'], 2) ?></td>
                <td><?= e((string) ($item['source_type'] ?? '-')) ?><?= $item['source_id'] !== null ? ' #' . (int) $item['source_id'] : '' ?></td>
                <td>
                    <?php if ($item['invoice_id'] !== null): ?>
                        <span class="cv-badge cv-badge--success">Invoiced (#<?= (int) $item['invoice_id'] ?>)</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Pending Invoice</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No billable items yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
