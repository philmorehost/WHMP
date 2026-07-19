<?php
/** @var array<string, mixed> $invoice */
/** @var array<int, array<string, mixed>> $items */
/** @var array<int, array<string, mixed>> $transactions */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Invoice INV-<?= (int) $invoice['id'] ?></h1>
    <p><a href="/admin/invoices">&larr; Back to invoices</a> &middot; <a href="/admin/invoices/<?= (int) $invoice['id'] ?>/pdf" target="_blank">Download PDF</a></p>
    <p><strong>Status:</strong>
        <?php if ($invoice['status'] === 'paid'): ?>
            <span class="cv-badge cv-badge--success">Paid</span>
        <?php elseif ($invoice['status'] === 'cancelled'): ?>
            <span class="cv-badge cv-badge--neutral">Cancelled</span>
        <?php else: ?>
            <span class="cv-badge cv-badge--danger">Unpaid</span>
        <?php endif; ?>
    </p>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);">
        <?php if ($invoice['status'] === 'unpaid'): ?>
            <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/mark-paid"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Mark Paid (Manual)</button>
            </form>
            <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/cancel"><?= csrf_field() ?>
                <button class="cv-btn cv-btn--danger" type="submit">Cancel</button>
            </form>
        <?php endif; ?>
        <a class="cv-btn cv-btn--secondary" href="/admin/credit-notes/create?client_id=<?= (int) $invoice['client_id'] ?>&invoice_id=<?= (int) $invoice['id'] ?>">Issue Credit Note</a>
    </div>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Items</h2>
    <table class="cv-table">
        <thead><tr><th>Description</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr><td><?= e($item['description']) ?></td><td>$<?= number_format((float) $item['amount'], 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td style="font-weight:700;">Total</td><td style="font-weight:700;">$<?= number_format((float) $invoice['total'], 2) ?></td></tr>
        </tfoot>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Transactions</h2>
    <table class="cv-table">
        <thead><tr><th>Date</th><th>Gateway</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><?= e($tx['created_at']) ?></td>
                <td><?= e($tx['gateway_slug']) ?></td>
                <td>$<?= number_format((float) $tx['amount'], 2) ?></td>
                <td><?= e($tx['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($transactions === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No transactions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
