<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $statusFilter */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>
<style>
/* Admin Invoices Page Styles */
.admin-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}
.admin-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.admin-hero__back {
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
.admin-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}

/* Status Filter Tabs */
.admin-status-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 32px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
}
.admin-status-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--cv-text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: .9rem;
    white-space: nowrap;
}
.admin-status-tab:hover {
    color: var(--cv-text-primary);
}
.admin-status-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}

/* Currency Stats */
.admin-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.admin-stats-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-stats-card__title {
    margin: 0 0 20px 0;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    padding-bottom: 16px;
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-stats-card__items {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}
.admin-stat-item {
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}
.admin-stat-item__label {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    display: block;
    margin-bottom: 8px;
}
.admin-stat-item__value {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    word-break: break-all;
}
.admin-stat-item--paid {
    background: linear-gradient(135deg, rgba(16,185,129,.1), rgba(5,150,105,.05));
    border: 1px solid rgba(16,185,129,.2);
}
.admin-stat-item--paid .admin-stat-item__label {
    color: #10b981;
}
.admin-stat-item--paid .admin-stat-item__value {
    color: #10b981;
}
.admin-stat-item--unpaid {
    background: linear-gradient(135deg, rgba(239,68,68,.1), rgba(220,38,38,.05));
    border: 1px solid rgba(239,68,68,.2);
}
.admin-stat-item--unpaid .admin-stat-item__label {
    color: #ef4444;
}
.admin-stat-item--unpaid .admin-stat-item__value {
    color: #ef4444;
}
.admin-stat-item--overdue {
    background: linear-gradient(135deg, rgba(245,158,11,.1), rgba(217,119,6,.05));
    border: 1px solid rgba(245,158,11,.2);
}
.admin-stat-item--overdue .admin-stat-item__label {
    color: #f59e0b;
}
.admin-stat-item--overdue .admin-stat-item__value {
    color: #f59e0b;
}

/* Toolbar */
.admin-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.admin-toolbar > div {
    flex: 1;
    min-width: 250px;
}
.admin-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}

/* Table */
.admin-table-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-table-wrapper {
    overflow-x: auto;
}
.admin-table {
    width: 100%;
    border-collapse: collapse;
}
.admin-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-table td {
    padding: 16px;
    color: var(--cv-text-primary);
}
.admin-table a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-table a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Badge */
.admin-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-badge--paid {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-badge--unpaid {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-badge--other {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}

/* Pagination */
.admin-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 16px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
    flex-wrap: wrap;
    gap: 12px;
}
.admin-pagination__info {
    color: var(--cv-text-secondary);
    font-size: .9rem;
    font-weight: 600;
}
.admin-pagination__controls {
    display: flex;
    gap: 8px;
}

/* Empty State */
.admin-empty-state {
    padding: 80px 40px;
    text-align: center;
}
.admin-empty-state__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.admin-empty-state__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.admin-empty-state__text {
    color: var(--cv-text-secondary);
    margin: 0;
}

@media (max-width: 768px) {
    .admin-hero {
        flex-direction: column;
        padding: 32px 24px;
    }
    .admin-hero__title {
        font-size: 1.5rem;
    }
    .admin-stats-card__items {
        grid-template-columns: 1fr;
    }
    .admin-table th:nth-child(n+4),
    .admin-table td:nth-child(n+4) {
        font-size: .8rem;
        padding: 12px 8px;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-hero">
    <div class="admin-hero__content">
        <a href="/admin" class="admin-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-hero__title">Manage Invoices</h1>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="admin-status-tabs">
    <a href="/admin/invoices" class="admin-status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
        All (<?= $results['total'] ?>)
    </a>
    <a href="/admin/invoices?status=unpaid" class="admin-status-tab <?= $statusFilter === 'unpaid' ? 'active' : '' ?>">
        🔴 Unpaid
    </a>
    <a href="/admin/invoices?status=paid" class="admin-status-tab <?= $statusFilter === 'paid' ? 'active' : '' ?>">
        🟢 Paid
    </a>
</div>

<!-- Currency Stats -->
<?php if (!empty($currencyStats)): ?>
    <div class="admin-stats-grid">
        <?php foreach ($currencyStats as $stat): ?>
            <div class="admin-stats-card">
                <h3 class="admin-stats-card__title">
                    💱 <?= e($stat['currency_code']) ?> (<?= e($stat['currency_symbol']) ?>)
                </h3>
                <div class="admin-stats-card__items">
                    <div class="admin-stat-item admin-stat-item--paid">
                        <span class="admin-stat-item__label">Paid</span>
                        <div class="admin-stat-item__value"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_paid'], 0) ?></div>
                    </div>
                    <div class="admin-stat-item admin-stat-item--unpaid">
                        <span class="admin-stat-item__label">Unpaid</span>
                        <div class="admin-stat-item__value"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_unpaid'], 0) ?></div>
                    </div>
                    <div class="admin-stat-item admin-stat-item--overdue">
                        <span class="admin-stat-item__label">Overdue</span>
                        <div class="admin-stat-item__value"><?= e($stat['currency_symbol']) ?><?= number_format((float) $stat['total_overdue'], 0) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Search Toolbar -->
<div class="admin-toolbar">
    <div>
        <?= $view->partial('partials.table-search', ['target' => '#invoices-table', 'placeholder' => 'Search by invoice #, client name, or email...']) ?>
    </div>
</div>

<!-- Invoices Table -->
<div class="admin-table-card">
    <?php if ($results['data'] === []): ?>
        <div class="admin-empty-state">
            <div class="admin-empty-state__icon">📄</div>
            <h2 class="admin-empty-state__title">No Invoices Found</h2>
            <p class="admin-empty-state__text">
                <?= !empty($statusFilter) ? 'No invoices match this status filter.' : 'No invoices have been created yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table" id="invoices-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Total</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results['data'] as $invoice): ?>
                    <tr>
                        <td>
                            <a href="/admin/invoices/<?= (int) $invoice['id'] ?>"><strong>INV-<?= (int) $invoice['id'] ?></strong></a>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <strong><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong>
                                <span style="font-size: .8rem; color: var(--cv-text-secondary);"><?= e($invoice['client_email']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span style="font-family: 'Monaco', 'Courier New', monospace; font-weight: 700;">
                                <?= e($invoice['currency_symbol'] ?? '$') ?><?= number_format((float) $invoice['total'] * (float) ($invoice['currency_rate'] ?? 1), 2) ?>
                            </span>
                            <span style="font-size: .75rem; color: var(--cv-text-secondary); margin-left: 6px;">
                                <?= e($invoice['currency_code'] ?? 'USD') ?>
                            </span>
                        </td>
                        <td style="font-size: .9rem; color: var(--cv-text-secondary);"><?= e($invoice['due_date']) ?></td>
                        <td>
                            <?php if ($invoice['status'] === 'paid'): ?>
                                <span class="admin-badge admin-badge--paid">Paid</span>
                            <?php elseif ($invoice['status'] === 'unpaid'): ?>
                                <span class="admin-badge admin-badge--unpaid">Unpaid</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--other"><?= e($invoice['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <a href="/admin/invoices/<?= (int) $invoice['id'] ?>" class="admin-btn admin-btn--secondary" style="padding: 6px 12px; font-size: .75rem; margin: 0;">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="admin-pagination">
            <div class="admin-pagination__info">
                Page <strong><?= $results['page'] ?></strong> of <strong><?= $totalPages ?></strong> (<?= number_format($results['total']) ?> total invoices)
            </div>
            <div class="admin-pagination__controls">
                <?php if ($results['page'] > 1): ?>
                    <a class="admin-btn admin-btn--secondary" href="/admin/invoices?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] - 1 ?>">← Previous</a>
                <?php endif; ?>
                <?php if ($results['page'] < $totalPages): ?>
                    <a class="admin-btn admin-btn--secondary" href="/admin/invoices?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] + 1 ?>">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
