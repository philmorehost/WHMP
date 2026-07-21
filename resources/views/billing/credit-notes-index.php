<?php
/** @var array<int, array<string, mixed>> $creditNotes */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Credit Notes</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn" href="/admin/credit-notes/create">Issue Credit Note</a>
    </div>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#credit-notes-table', 'placeholder' => 'Search credit notes...']) ?>
    </div>
    <table class="cv-table" id="credit-notes-table">
        <thead><tr><th>#</th><th>Client</th><th>Reason</th><th>Total</th><th>Invoice</th><th>Issued</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($creditNotes as $note): ?>
            <tr>
                <td><a href="/admin/credit-notes/<?= (int) $note['id'] ?>">CN-<?= (int) $note['id'] ?></a></td>
                <td><?= e($note['first_name'] . ' ' . $note['last_name']) ?> (<?= e($note['client_email']) ?>)</td>
                <td><?= e($note['reason']) ?></td>
                <td><?= e($note['currency_symbol'] ?? '$') ?><?= number_format((float) $note['total'], 2) ?></td>
                <td><?= $note['invoice_id'] !== null ? '<a href="/admin/invoices/' . (int) $note['invoice_id'] . '">INV-' . (int) $note['invoice_id'] . '</a>' : '&mdash;' ?></td>
                <td><?= e($note['created_at']) ?></td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/credit-notes/<?= (int) $note['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($creditNotes === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No credit notes issued yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
