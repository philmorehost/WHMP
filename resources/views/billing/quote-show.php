<?php
/** @var array<string, mixed> $quote */
/** @var array<int, array<string, mixed>> $items */
/** @var array<string, mixed>|null $client */
?>
<style>
/* Admin Quote Detail Styles */
.admin-quote-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-quote-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-quote-hero__content {
    position: relative;
    z-index: 1;
}
.admin-quote-hero__back {
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
.admin-quote-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-quote-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-quote-hero__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    margin-top: 24px;
}
.admin-quote-hero__meta-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-quote-hero__meta-label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.admin-quote-hero__meta-value {
    font-size: .95rem;
    color: white;
    font-weight: 600;
}
.admin-quote-hero__meta-link {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-quote-hero__meta-link:hover {
    color: #93c5fd;
    text-decoration: underline;
}

/* Actions */
.admin-quote-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.admin-quote-btn {
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
.admin-quote-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-quote-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-quote-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-quote-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-quote-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-quote-btn--danger:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Quote Card */
.admin-quote-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    overflow: hidden;
}
.admin-quote-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-quote-card__body {
    padding: 24px;
}

/* Table */
.admin-quote-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-quote-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-quote-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-quote-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-quote-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-quote-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-quote-table td:last-child {
    text-align: right;
    font-family: 'Monaco', 'Courier New', monospace;
    font-weight: 700;
}
.admin-quote-table tfoot tr {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-top: 2px solid var(--cv-border-default);
}
.admin-quote-table tfoot td {
    padding: 16px;
}

@media (max-width: 768px) {
    .admin-quote-hero {
        padding: 32px 24px;
    }
    .admin-quote-hero__title {
        font-size: 1.5rem;
    }
    .admin-quote-hero__meta {
        grid-template-columns: 1fr;
    }
    .admin-quote-actions {
        flex-direction: column;
    }
    .admin-quote-actions form,
    .admin-quote-actions button {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-quote-hero">
    <div class="admin-quote-hero__content">
        <a href="/admin/quotes" class="admin-quote-hero__back">
            <span>←</span>
            <span>Back to Quotes</span>
        </a>
        <h1 class="admin-quote-hero__title">Quote Q-<?= (int) $quote['id'] ?></h1>

        <div class="admin-quote-hero__meta">
            <div class="admin-quote-hero__meta-item">
                <span class="admin-quote-hero__meta-label">👤 Client</span>
                <span class="admin-quote-hero__meta-value">
                    <?= $client !== null ? e($client['first_name'] . ' ' . $client['last_name']) : 'Unknown' ?>
                </span>
                <?php if ($client !== null): ?>
                    <span style="font-size:.8rem; color:rgba(255,255,255,.6);"><?= e($client['email']) ?></span>
                <?php endif; ?>
            </div>
            <div class="admin-quote-hero__meta-item">
                <span class="admin-quote-hero__meta-label">📋 Subject</span>
                <span class="admin-quote-hero__meta-value"><?= e($quote['subject']) ?></span>
            </div>
            <div class="admin-quote-hero__meta-item">
                <span class="admin-quote-hero__meta-label">🎯 Status</span>
                <span class="admin-quote-hero__meta-value"><?= e(ucfirst((string) $quote['status'])) ?></span>
            </div>
            <?php if (!empty($quote['valid_until'])): ?>
                <div class="admin-quote-hero__meta-item">
                    <span class="admin-quote-hero__meta-label">📅 Valid Until</span>
                    <span class="admin-quote-hero__meta-value"><?= e((string) $quote['valid_until']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($quote['invoice_id'] !== null): ?>
                <div class="admin-quote-hero__meta-item">
                    <span class="admin-quote-hero__meta-label">🔗 Converted</span>
                    <span class="admin-quote-hero__meta-value">
                        <a href="/admin/invoices/<?= (int) $quote['invoice_id'] ?>" class="admin-quote-hero__meta-link">
                            INV-<?= (int) $quote['invoice_id'] ?>
                        </a>
                    </span>
                </div>
            <?php endif; ?>
            <div class="admin-quote-hero__meta-item">
                <span class="admin-quote-hero__meta-label">🕐 Created</span>
                <span class="admin-quote-hero__meta-value"><?= e($quote['created_at']) ?></span>
            </div>
        </div>

        <div class="admin-quote-actions">
            <a href="/admin/quotes/<?= (int) $quote['id'] ?>/pdf" class="admin-quote-btn admin-quote-btn--secondary" target="_blank">📥 Download PDF</a>
            <?php if ($quote['status'] === 'draft'): ?>
                <form method="post" action="/admin/quotes/<?= (int) $quote['id'] ?>/send"><?= csrf_field() ?>
                    <button class="admin-quote-btn admin-quote-btn--primary" type="submit">✉️ Send to Client</button>
                </form>
                <form method="post" action="/admin/quotes/<?= (int) $quote['id'] ?>/delete" data-confirm="Delete this draft quote? This cannot be undone."><?= csrf_field() ?>
                    <button class="admin-quote-btn admin-quote-btn--danger" type="submit">🗑️ Delete Draft</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="admin-quote-card">
    <h2 class="admin-quote-card__title">📄 Quote Items</h2>
    <div class="admin-quote-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-quote-table">
                <thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= e($item['description']) ?></td><td>$<?= number_format((float) $item['amount'], 2) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td>Total</td><td>$<?= number_format((float) $quote['total'], 2) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
