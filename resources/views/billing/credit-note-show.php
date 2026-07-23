<?php
/** @var array<string, mixed> $creditNote */
/** @var array<int, array<string, mixed>> $items */
/** @var array<string, mixed>|null $client */
?>
<style>
/* Admin Credit Note Detail Styles */
.admin-cn-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-cn-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-cn-hero__content {
    position: relative;
    z-index: 1;
}
.admin-cn-hero__back {
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
.admin-cn-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-cn-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-cn-hero__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    margin-top: 24px;
}
.admin-cn-hero__meta-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-cn-hero__meta-label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.admin-cn-hero__meta-value {
    font-size: .95rem;
    color: white;
    font-weight: 600;
}
.admin-cn-hero__meta-link {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-cn-hero__meta-link:hover {
    color: #93c5fd;
    text-decoration: underline;
}

/* Actions */
.admin-cn-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.admin-cn-btn {
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
.admin-cn-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-cn-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}

/* Card */
.admin-cn-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    overflow: hidden;
}
.admin-cn-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-cn-card__body {
    padding: 24px;
}

/* Table */
.admin-cn-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-cn-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-cn-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-cn-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-cn-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-cn-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-cn-table td:last-child {
    text-align: right;
    font-family: 'Monaco', 'Courier New', monospace;
    font-weight: 700;
}
.admin-cn-table tfoot tr {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-top: 2px solid var(--cv-border-default);
}
.admin-cn-table tfoot td {
    padding: 16px;
}

@media (max-width: 768px) {
    .admin-cn-hero {
        padding: 32px 24px;
    }
    .admin-cn-hero__title {
        font-size: 1.5rem;
    }
    .admin-cn-hero__meta {
        grid-template-columns: 1fr;
    }
    .admin-cn-actions {
        flex-direction: column;
    }
    .admin-cn-actions a,
    .admin-cn-actions button {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-cn-hero">
    <div class="admin-cn-hero__content">
        <a href="/admin/credit-notes" class="admin-cn-hero__back">
            <span>←</span>
            <span>Back to Credit Notes</span>
        </a>
        <h1 class="admin-cn-hero__title">Credit Note CN-<?= (int) $creditNote['id'] ?></h1>

        <div class="admin-cn-hero__meta">
            <div class="admin-cn-hero__meta-item">
                <span class="admin-cn-hero__meta-label">👤 Client</span>
                <span class="admin-cn-hero__meta-value">
                    <?= $client !== null ? e($client['first_name'] . ' ' . $client['last_name']) : 'Unknown' ?>
                </span>
                <?php if ($client !== null): ?>
                    <span style="font-size:.8rem; color:rgba(255,255,255,.6);"><?= e($client['email']) ?></span>
                <?php endif; ?>
            </div>
            <div class="admin-cn-hero__meta-item">
                <span class="admin-cn-hero__meta-label">📝 Reason</span>
                <span class="admin-cn-hero__meta-value"><?= e($creditNote['reason']) ?></span>
            </div>
            <?php if ($creditNote['invoice_id'] !== null): ?>
                <div class="admin-cn-hero__meta-item">
                    <span class="admin-cn-hero__meta-label">🔗 Related Invoice</span>
                    <span class="admin-cn-hero__meta-value">
                        <a href="/admin/invoices/<?= (int) $creditNote['invoice_id'] ?>" class="admin-cn-hero__meta-link">
                            INV-<?= (int) $creditNote['invoice_id'] ?>
                        </a>
                    </span>
                </div>
            <?php endif; ?>
            <div class="admin-cn-hero__meta-item">
                <span class="admin-cn-hero__meta-label">🕐 Issued</span>
                <span class="admin-cn-hero__meta-value"><?= e($creditNote['created_at']) ?></span>
            </div>
        </div>

        <div class="admin-cn-actions">
            <a href="/admin/credit-notes/<?= (int) $creditNote['id'] ?>/pdf" class="admin-cn-btn admin-cn-btn--secondary" target="_blank">📥 Download PDF</a>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="admin-cn-card">
    <h2 class="admin-cn-card__title">📄 Credit Items</h2>
    <div class="admin-cn-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-cn-table">
                <thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= e($item['description']) ?></td><td>$<?= number_format((float) $item['amount'], 2) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td>Total Credit</td><td>$<?= number_format((float) $creditNote['total'], 2) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
