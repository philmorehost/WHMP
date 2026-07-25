<?php
/** @var array<string, mixed> $invoice */
/** @var array<int, array<string, mixed>> $items */
/** @var array<int, array<string, mixed>> $transactions */
/** @var string|null $refundSuccess */
/** @var string|null $refundError */
/** @var array<string, mixed> $currency */
// Stored amounts are authoritative in the BASE currency (see CurrencyService);
// the invoice's locked rate converts them for display only.
$rate = (float) $invoice['currency_rate'] > 0 ? (float) $invoice['currency_rate'] : 1.0;
$money = static fn (float $amount): string => $currency['symbol'] . number_format(round($amount * $rate, 2), 2);
?>
<style>
/* Admin Invoice Detail Styles */
.admin-invoice-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-invoice-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-invoice-hero__content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    flex-wrap: wrap;
}
.admin-invoice-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.admin-invoice-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-invoice-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-invoice-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-invoice-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-invoice-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-invoice-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-invoice-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-invoice-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-invoice-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-invoice-btn--danger:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Invoice Card */
.admin-invoice-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-invoice-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-invoice-card__body {
    padding: 24px;
}

/* Table Styles */
.admin-invoice-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-invoice-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-invoice-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-invoice-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-invoice-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-invoice-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-invoice-table tfoot tr {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-top: 2px solid var(--cv-border-default);
    font-weight: 700;
}
.admin-invoice-table tfoot td {
    padding: 16px;
}
.admin-invoice-table button {
    padding: 6px 12px;
    font-size: .75rem;
}

/* Status Badge */
.admin-invoice-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-invoice-badge--paid {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-invoice-badge--unpaid {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-invoice-badge--cancelled,
.admin-invoice-badge--refunded {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}

/* Message */
.admin-invoice-message {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: .9rem;
    border-left: 4px solid;
}
.admin-invoice-message--success {
    background: linear-gradient(135deg, rgba(16,185,129,.15), rgba(5,150,105,.1));
    border-left-color: #10b981;
    color: #059669;
}
.admin-invoice-message--error {
    background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(220,38,38,.1));
    border-left-color: #ef4444;
    color: #dc2626;
}

@media (max-width: 768px) {
    .admin-invoice-hero {
        padding: 32px 24px;
    }
    .admin-invoice-hero__title {
        font-size: 1.5rem;
    }
    .admin-invoice-hero__content {
        flex-direction: column;
    }
    .admin-invoice-actions {
        width: 100%;
        flex-direction: column;
    }
    .admin-invoice-actions form,
    .admin-invoice-actions a {
        width: 100%;
    }
}
</style>

<?php if (!empty($refundSuccess)): ?>
    <div class="admin-invoice-message admin-invoice-message--success">
        ✅ <?= e($refundSuccess) ?>
    </div>
<?php elseif (!empty($refundError)): ?>
    <div class="admin-invoice-message admin-invoice-message--error">
        ⚠️ <?= e($refundError) ?>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<div class="admin-invoice-hero">
    <div class="admin-invoice-hero__content">
        <div>
            <a href="/admin/invoices" class="admin-invoice-hero__back">
                <span>←</span>
                <span>Back to Invoices</span>
            </a>
            <h1 class="admin-invoice-hero__title">Invoice INV-<?= (int) $invoice['id'] ?></h1>
            <p style="margin:8px 0 0 0; color:rgba(255,255,255,.7);">
                Status:
                <?php if ($invoice['status'] === 'paid'): ?>
                    <span class="admin-invoice-badge admin-invoice-badge--paid">Paid</span>
                <?php elseif ($invoice['status'] === 'cancelled'): ?>
                    <span class="admin-invoice-badge admin-invoice-badge--cancelled">Cancelled</span>
                <?php elseif ($invoice['status'] === 'refunded'): ?>
                    <span class="admin-invoice-badge admin-invoice-badge--refunded">Refunded</span>
                <?php else: ?>
                    <span class="admin-invoice-badge admin-invoice-badge--unpaid">Unpaid</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="admin-invoice-actions">
            <?php if ($invoice['status'] === 'unpaid'): ?>
                <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/mark-paid"><?= csrf_field() ?>
                    <button class="admin-invoice-btn admin-invoice-btn--primary" type="submit">✓ Mark Paid</button>
                </form>
                <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/cancel"><?= csrf_field() ?>
                    <button class="admin-invoice-btn admin-invoice-btn--danger" type="submit">✕ Cancel</button>
                </form>
            <?php endif; ?>
            <a class="admin-invoice-btn admin-invoice-btn--secondary" href="/admin/invoices/<?= (int) $invoice['id'] ?>/pdf" target="_blank">📥 Download PDF</a>
            <a class="admin-invoice-btn admin-invoice-btn--secondary" href="/admin/credit-notes/create?client_id=<?= (int) $invoice['client_id'] ?>&invoice_id=<?= (int) $invoice['id'] ?>">📋 Issue Credit Note</a>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="admin-invoice-card">
    <h2 class="admin-invoice-card__title">📄 Items</h2>
    <div class="admin-invoice-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-invoice-table">
                <thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= e($item['description']) ?></td><td style="text-align:right; font-family:'Monaco', 'Courier New', monospace; font-weight:700;"><?= $money((float) $item['amount']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td>Total</td><td style="text-align:right; font-family:'Monaco', 'Courier New', monospace;"><?= $money((float) $invoice['total']) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Transactions Table -->
<div class="admin-invoice-card">
    <h2 class="admin-invoice-card__title">💳 Transactions</h2>
    <div class="admin-invoice-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-invoice-table">
                <thead><tr><th>Date</th><th>Gateway</th><th style="text-align:right;">Amount</th><th>Status</th><th style="width:100px;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td style="font-size:.85rem; color:var(--cv-text-secondary);"><?= e($tx['created_at']) ?></td>
                        <td><?= e($tx['gateway_slug']) ?></td>
                        <td style="text-align:right; font-family:'Monaco', 'Courier New', monospace; font-weight:700;"><?= $money((float) $tx['amount']) ?></td>
                        <td><span style="display:inline-block; padding:4px 8px; background:linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15)); color:#10b981; border:1px solid rgba(16,185,129,.3); border-radius:6px; font-size:.75rem; font-weight:700;"><?= e($tx['status']) ?></span></td>
                        <td>
                            <?php if ($tx['status'] === 'completed'): ?>
                                <button type="button" class="admin-invoice-btn admin-invoice-btn--secondary" data-toggle-target="#refund-form-<?= (int) $tx['id'] ?>" data-toggle-display="table-row">⮌ Refund</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($tx['status'] === 'completed'): ?>
                        <tr id="refund-form-<?= (int) $tx['id'] ?>" style="display:none;">
                            <td colspan="5" style="padding:24px;">
                                <form method="post" action="/admin/invoices/<?= (int) $invoice['id'] ?>/refund" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;align-items:end;" data-confirm="Refund <?= $money((float) $tx['amount']) ?> from this transaction?"><?= csrf_field() ?>
                                    <input type="hidden" name="transaction_id" value="<?= (int) $tx['id'] ?>">
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <label style="font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em;">Refund To</label>
                                        <select name="method" style="padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary);">
                                            <option value="wallet">Client Wallet (Account Credit)</option>
                                            <option value="gateway">External (via <?= e($tx['gateway_slug']) ?>)</option>
                                        </select>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <label style="font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em;">Amount</label>
                                        <input type="number" step="0.01" min="0" max="<?= (float) $tx['amount'] ?>" name="amount" placeholder="Full: <?= number_format((float) $tx['amount'], 2) ?>" style="padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary);">
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <label style="font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em;">Reason (wallet only)</label>
                                        <input type="text" name="reason" placeholder="Reason for refund" style="padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary);">
                                    </div>
                                    <button class="admin-invoice-btn admin-invoice-btn--danger" type="submit">Issue Refund</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($transactions === []): ?>
                    <tr><td colspan="5" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No transactions yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
