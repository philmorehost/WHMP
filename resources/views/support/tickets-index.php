<?php
/** @var array<int, array<string, mixed>> $tickets */
/** @var array<int, array<string, mixed>> $departments */
/** @var string $statusFilter */
/** @var mixed $departmentFilter */
?>
<style>
/* Admin Tickets Page Styles */
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
.admin-hero__links {
    display: flex;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
}
.admin-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: rgba(255,255,255,.75);
    text-decoration: none;
    font-size: .85rem;
    padding: 6px 12px;
    background: rgba(255,255,255,.1);
    border-radius: 6px;
    transition: all 0.2s;
}
.admin-hero__link:hover {
    background: rgba(255,255,255,.2);
    color: #fff;
}

/* Status Tabs */
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
.admin-badge--open {
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    color: #3b82f6;
    border: 1px solid rgba(59,130,246,.3);
}
.admin-badge--customer-reply {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-badge--answered {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-badge--closed {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}
.admin-badge--high {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
}
.admin-badge--medium {
    background: linear-gradient(135deg, rgba(245,158,11,.2), rgba(217,119,6,.15));
    color: #f59e0b;
}
.admin-badge--low {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
}

/* Pagination */
.admin-pagination {
    display: flex;
    justify-content: center;
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
    .admin-hero__links {
        width: 100%;
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
        <h1 class="admin-hero__title">Support Tickets</h1>
        <div class="admin-hero__links">
            <a href="/admin/departments" class="admin-hero__link">
                <span>👥</span>
                <span>Departments</span>
            </a>
            <a href="/admin/canned-replies" class="admin-hero__link">
                <span>💬</span>
                <span>Canned Replies</span>
            </a>
        </div>
    </div>
</div>

<!-- Status Tabs -->
<div class="admin-status-tabs">
    <a href="/admin/tickets" class="admin-status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
        All (<?= count($tickets) ?>)
    </a>
    <a href="/admin/tickets?status=open" class="admin-status-tab <?= $statusFilter === 'open' ? 'active' : '' ?>">
        🟢 Open
    </a>
    <a href="/admin/tickets?status=customer-reply" class="admin-status-tab <?= $statusFilter === 'customer-reply' ? 'active' : '' ?>">
        🟠 Awaiting Support
    </a>
    <a href="/admin/tickets?status=answered" class="admin-status-tab <?= $statusFilter === 'answered' ? 'active' : '' ?>">
        🔵 Answered
    </a>
    <a href="/admin/tickets?status=closed" class="admin-status-tab <?= $statusFilter === 'closed' ? 'active' : '' ?>">
        ⚫ Closed
    </a>
</div>

<!-- Search Toolbar -->
<div class="admin-toolbar">
    <div>
        <?= $view->partial('partials.table-search', ['target' => '#tickets-table', 'placeholder' => 'Search by ticket #, subject, or client...']) ?>
    </div>
</div>

<!-- Tickets Table -->
<div class="admin-table-card">
    <?php if ($tickets === []): ?>
        <div class="admin-empty-state">
            <div class="admin-empty-state__icon">💬</div>
            <h2 class="admin-empty-state__title">No Tickets Found</h2>
            <p class="admin-empty-state__text">
                <?= !empty($statusFilter) ? 'No tickets match this status filter.' : 'No support tickets have been created yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table" id="tickets-table">
                <thead>
                    <tr>
                        <th>Ticket ID</th>
                        <th>Subject</th>
                        <th>Department</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Last Reply</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><strong>#<?= (int) $ticket['id'] ?></strong></td>
                        <td><?= e($ticket['subject']) ?></td>
                        <td><?= e($ticket['department_name']) ?></td>
                        <td>
                            <?php if ($ticket['priority'] === 'high'): ?>
                                <span class="admin-badge admin-badge--high">High</span>
                            <?php elseif ($ticket['priority'] === 'low'): ?>
                                <span class="admin-badge admin-badge--low">Low</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--medium">Medium</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ticket['status'] === 'open'): ?>
                                <span class="admin-badge admin-badge--open">Open</span>
                            <?php elseif ($ticket['status'] === 'customer-reply'): ?>
                                <span class="admin-badge admin-badge--customer-reply">Awaiting Support</span>
                            <?php elseif ($ticket['status'] === 'answered'): ?>
                                <span class="admin-badge admin-badge--answered">Answered</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--closed">Closed</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($ticket['assigned_admin_name'] ?? '—')) ?></td>
                        <td style="font-size: .85rem; color: var(--cv-text-secondary);"><?= e((string) ($ticket['last_reply_at'] ?? '-')) ?></td>
                        <td style="text-align: center;">
                            <a href="/admin/tickets/<?= (int) $ticket['id'] ?>" class="admin-btn admin-btn--secondary" style="padding: 6px 12px; font-size: .75rem; margin: 0;">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if (isset($pagination) && $pagination['total'] > 20): ?>
            <?php
            $totalPages = (int) ceil($pagination['total'] / 20);
            $currentPage = $pagination['page'];
            $queryStr = '';
            if ($statusFilter !== '') {
                $queryStr .= '&status=' . urlencode($statusFilter);
            }
            if ($departmentFilter !== null && $departmentFilter !== '') {
                $queryStr .= '&department_id=' . urlencode((string)$departmentFilter);
            }
            ?>
            <div class="admin-pagination">
                <div class="admin-pagination__info">
                    Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong>
                </div>
                <div class="admin-pagination__controls">
                    <?php if ($currentPage > 1): ?>
                        <a class="admin-btn admin-btn--secondary" href="/admin/tickets?page=<?= $currentPage - 1 ?><?= $queryStr ?>">← Previous</a>
                    <?php endif; ?>
                    <?php if ($currentPage < $totalPages): ?>
                        <a class="admin-btn admin-btn--secondary" href="/admin/tickets?page=<?= $currentPage + 1 ?><?= $queryStr ?>">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
