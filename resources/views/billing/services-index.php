<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $statusFilter */
$totalPages = (int) ceil(($results['total'] ?? 0) / ($results['perPage'] ?? 20));

// Count services by status for display
$activeCount = 0;
$suspendedCount = 0;
$pendingCount = 0;
?>
<style>
/* Reuse admin styles from clients page */
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
.admin-hero__stat {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-top: 16px;
}
.admin-hero__stat-item {
    text-align: center;
}
.admin-hero__stat-label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
    display: block;
    margin-bottom: 4px;
}
.admin-hero__stat-value {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.75rem;
    font-weight: 900;
    color: #3b82f6;
}

/* Status Filter Tabs */
.admin-status-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
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

/* Toolbar */
.admin-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    align-items: center;
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
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
    font-size: .9rem;
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
    vertical-align: middle;
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
.admin-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-badge--suspended {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-badge--pending {
    background: linear-gradient(135deg, rgba(245,158,11,.2), rgba(217,119,6,.15));
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,.3);
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
    .admin-hero__stat {
        flex-direction: column;
        gap: 12px;
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
        <h1 class="admin-hero__title">Manage Services</h1>
        <div class="admin-hero__stat">
            <div class="admin-hero__stat-item">
                <span class="admin-hero__stat-label">Total Services</span>
                <span class="admin-hero__stat-value"><?= number_format($results['total']) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="admin-status-tabs">
    <a href="/admin/services" class="admin-status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
        All (<?= $results['total'] ?>)
    </a>
    <a href="/admin/services?status=active" class="admin-status-tab <?= $statusFilter === 'active' ? 'active' : '' ?>">
        🟢 Active
    </a>
    <a href="/admin/services?status=suspended" class="admin-status-tab <?= $statusFilter === 'suspended' ? 'active' : '' ?>">
        🔴 Suspended
    </a>
    <a href="/admin/services?status=pending" class="admin-status-tab <?= $statusFilter === 'pending' ? 'active' : '' ?>">
        🟡 Pending
    </a>
</div>

<!-- Search Toolbar -->
<div class="admin-toolbar">
    <div style="flex: 1; min-width: 250px;">
        <?= $view->partial('partials.table-search', ['target' => '#services-table', 'placeholder' => 'Search by client name, email, or product...']) ?>
    </div>
</div>

<!-- Services Table -->
<div class="admin-table-card">
    <?php if ($results['data'] === []): ?>
        <div class="admin-empty-state">
            <div class="admin-empty-state__icon">🖥️</div>
            <h2 class="admin-empty-state__title">No Services Found</h2>
            <p class="admin-empty-state__text">
                <?= !empty($statusFilter) ? 'No services match this status filter.' : 'No services have been created yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table" id="services-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Product</th>
                        <th>Cycle</th>
                        <th>Amount</th>
                        <th>Next Due</th>
                        <th>Status</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results['data'] as $service): ?>
                    <tr>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <strong><?= e($service['first_name'] . ' ' . $service['last_name']) ?></strong>
                                <span style="font-size: .8rem; color: var(--cv-text-secondary);"><?= e($service['client_email']) ?></span>
                            </div>
                        </td>
                        <td><strong><?= e($service['product_name']) ?></strong></td>
                        <td><?= e($service['billing_cycle']) ?></td>
                        <td>
                            <span style="font-family: 'Monaco', 'Courier New', monospace; font-weight: 700;">
                                <?= e($service['currency_symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?>
                            </span>
                        </td>
                        <td style="font-size: .85rem; color: var(--cv-text-secondary);"><?= e($service['next_due_date']) ?></td>
                        <td>
                            <?php if ($service['status'] === 'active'): ?>
                                <span class="admin-badge admin-badge--active">Active</span>
                            <?php elseif ($service['status'] === 'suspended'): ?>
                                <span class="admin-badge admin-badge--suspended">Suspended</span>
                            <?php elseif ($service['status'] === 'pending'): ?>
                                <span class="admin-badge admin-badge--pending">Pending</span>
                            <?php else: ?>
                                <span class="admin-badge" style="background: rgba(107,114,128,.1); color: #6b7280; border: 1px solid rgba(107,114,128,.2);"><?= e($service['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <a href="/admin/services/<?= (int) $service['id'] ?>" class="admin-btn admin-btn--secondary" style="padding: 6px 12px; font-size: .75rem; margin: 0;">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="admin-pagination">
            <div class="admin-pagination__info">
                Page <strong><?= $results['page'] ?></strong> of <strong><?= max(1, $totalPages) ?></strong> (<?= number_format($results['total']) ?> total services)
            </div>
            <div class="admin-pagination__controls">
                <?php if ($results['page'] > 1): ?>
                    <a class="admin-btn admin-btn--secondary" href="/admin/services?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] - 1 ?>">← Previous</a>
                <?php endif; ?>
                <?php if ($results['page'] < $totalPages): ?>
                    <a class="admin-btn admin-btn--secondary" href="/admin/services?status=<?= urlencode($statusFilter) ?>&page=<?= $results['page'] + 1 ?>">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
