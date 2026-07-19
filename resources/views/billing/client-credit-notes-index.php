<?php
/** @var array<int, array<string, mixed>> $creditNotes */
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">My Credit Notes</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a> &middot; <a href="/client/invoices">My Invoices</a></p>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-credit-notes-table', 'placeholder' => 'Search credit notes...']) ?>
    </div>
    <table class="cv-table" id="my-credit-notes-table">
        <thead><tr><th>#</th><th>Reason</th><th>Amount</th><th>Issued</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($creditNotes as $note): ?>
            <tr>
                <td>CN-<?= (int) $note['id'] ?></td>
                <td><?= e($note['reason']) ?></td>
                <td>$<?= number_format((float) $note['total'], 2) ?></td>
                <td><?= e($note['created_at']) ?></td>
                <td><a href="/client/credit-notes/<?= (int) $note['id'] ?>/pdf" target="_blank">Download PDF</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($creditNotes === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No credit notes yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
