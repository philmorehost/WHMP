<?php
/** @var array<int, array<string, mixed>> $invoices */
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">My Invoices</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a> &middot; <a href="/client/payment-methods">Manage payment methods</a></p>

    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-invoices-table', 'placeholder' => 'Search invoices...']) ?>
    </div>
    <table class="cv-table" id="my-invoices-table">
        <thead><tr><th>#</th><th>Total</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><a href="/client/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                <td><?= e($invoice['currency']['symbol']) ?><?= number_format((float) $invoice['total'] * (float) $invoice['currency_rate'], 2) ?></td>
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
            </tr>
        <?php endforeach; ?>
        <?php if ($invoices === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No invoices yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
