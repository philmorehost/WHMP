<?php
/** @var array<string, mixed> $invoice */
/** @var array<int, array<string, mixed>> $items */
/** @var array<int, array<string, mixed>> $transactions */
/** @var string|null $refundSuccess */
/** @var string|null $refundError */
/** @var array<string, mixed> $currency */
$rate = (float) $invoice['currency_rate'];
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * $rate, 2);
?>
<?php if (!empty($refundSuccess)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);border-left:4px solid #22c55e;">
        <?= e($refundSuccess) ?>
    </div>
<?php elseif (!empty($refundError)): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);border-left:4px solid #ef4444;">
        <?= e($refundError) ?>
    </div>
<?php endif; ?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Invoice INV-<?= (int) $invoice['id'] ?></h1>
    <p><a href="/admin/invoices">&larr; Back to invoices</a> &middot; <a href="/admin/invoices/<?= (int) $invoice['id'] ?>/pdf" target="_blank">Download PDF</a></p>
    <p><strong>Status:</strong>
        <?php if ($invoice['status'] === 'paid'): ?>
            <span class="cv-badge cv-badge--success">Paid</span>
        <?php elseif ($invoice['status'] === 'cancelled'): ?>
            <span class="cv-badge cv-badge--neutral">Cancelled</span>
        <?php elseif ($invoice['status'] === 'refunded'): ?>
            <span class="cv-badge cv-badge--neutral">Refunded</span>
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
            <tr><td><?= e($item['description']) ?></td><td><?= $money((float) $item['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td style="font-weight:700;">Total</td><td style="font-weight:700;"><?= $money((float) $invoice['total']) ?></td></tr>
        </tfoot>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Transactions</h2>
    <table class="cv-table">
        <thead><tr><th>Date</th><th>Gateway</th><th>Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><?= e($tx['created_at']) ?></td>
                <td><?= e($tx['gateway_slug']) ?></td>
                <td><?= $money((float) $tx['amount']) ?></td>
                <td><?= e($tx['status']) ?></td>
                <td>
                    <?php if ($tx['status'] === 'completed'): ?>
                        <button type="button" class="cv-btn cv-btn--secondary" data-toggle-target="#refund-form-<?= (int) $tx['id'] ?>" data-toggle-display="table-row">Refund</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($tx['status'] === 'completed'): ?>
                <tr id="refund-form-<?= (int) $tx['id'] ?>" style="display:none;">
                    <td colspan="5">
                        <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/refund" style="display:grid;grid-template-columns:1fr 1fr 2fr auto;gap:var(--cv-space-3);align-items:end;" data-confirm="Refund <?= $money((float) $tx['amount']) ?> from this transaction?"><?= csrf_field() ?>
                            <input type="hidden" name="transaction_id" value="<?= (int) $tx['id'] ?>">
                            <div class="cv-field" style="margin-bottom:0;">
                                <label class="cv-label">Refund To</label>
                                <select class="cv-select" name="method">
                                    <option value="wallet">Client Wallet (Account Credit)</option>
                                    <option value="gateway">External (via <?= e($tx['gateway_slug']) ?>)</option>
                                </select>
                            </div>
                            <div class="cv-field" style="margin-bottom:0;">
                                <label class="cv-label">Amount (blank = full <?= $money((float) $tx['amount']) ?>)</label>
                                <input class="cv-input" type="number" step="0.01" min="0" max="<?= (float) $tx['amount'] ?>" name="amount" placeholder="<?= number_format((float) $tx['amount'], 2) ?>">
                            </div>
                            <div class="cv-field" style="margin-bottom:0;">
                                <label class="cv-label">Reason (wallet refunds only)</label>
                                <input class="cv-input" name="reason" placeholder="Reason for refund">
                            </div>
                            <button class="cv-btn cv-btn--danger" type="submit">Issue Refund</button>
                        </form>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($transactions === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No transactions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
