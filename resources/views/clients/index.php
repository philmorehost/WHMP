<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $search */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>
<style>
/* ====== Admin Clients Page Styles ====== */
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

/* Toolbar */
.admin-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    align-items: center;
}
.admin-toolbar__search {
    display: flex;
    gap: 8px;
    flex: 1;
    min-width: 250px;
}
.admin-toolbar__search input {
    flex: 1;
    padding: 10px 16px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .95rem;
}
.admin-toolbar__search input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-toolbar__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
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

/* Modern Table */
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

/* Cells */
.admin-table__name {
    font-weight: 700;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.admin-table__name-main {
    color: var(--cv-text-primary);
}
.admin-table__name-email {
    font-size: .8rem;
    color: var(--cv-text-secondary);
    font-weight: 400;
}
.admin-table__services {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .85rem;
    font-weight: 700;
}
.admin-table__services-active {
    color: #10b981;
}
.admin-table__services-total {
    color: var(--cv-text-secondary);
}

/* Badge Styles */
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
.admin-badge--closed {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-badge--inactive {
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

/* Mobile Responsive */
@media (max-width: 768px) {
    .admin-hero {
        flex-direction: column;
        padding: 32px 24px;
        text-align: center;
    }
    .admin-hero__title {
        font-size: 1.5rem;
    }
    .admin-hero__stat {
        flex-direction: column;
        gap: 12px;
        margin-top: 16px;
    }
    .admin-toolbar {
        flex-direction: column;
    }
    .admin-toolbar__search {
        width: 100%;
    }
    .admin-table th:not(:first-child),
    .admin-table td:not(:first-child) {
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
        <h1 class="admin-hero__title">Manage Clients</h1>
        <div class="admin-hero__stat">
            <div class="admin-hero__stat-item">
                <span class="admin-hero__stat-label">Total Clients</span>
                <span class="admin-hero__stat-value"><?= number_format($results['total']) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="admin-toolbar">
    <form method="get" action="/admin/clients" class="admin-toolbar__search" id="admin-clients-search-form">
        <input type="text" name="q" id="client-search-input" value="<?= e($search) ?>" placeholder="Search by name, email, or company..." aria-label="Search clients">
        <button class="admin-btn admin-btn--secondary" type="submit">🔍 Search</button>
    </form>
    <div class="admin-toolbar__actions">
        <button class="admin-btn admin-btn--danger" type="submit" form="bulk-clients-form" id="bulk-delete-clients-btn" disabled style="background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.4);color:#ef4444;">🗑️ Bulk Delete Selected</button>
        <a class="admin-btn admin-btn--secondary" href="/admin/clients/export?q=<?= urlencode($search) ?>">📥 Export CSV</a>
        <a class="admin-btn" href="/admin/clients/create">➕ Add Client</a>
        <a class="admin-btn admin-btn--secondary" href="/admin/client-groups">👥 Groups</a>
    </div>
</div>

<div id="admin-client-results">
    <?= $view->partial('clients.index-results', [
        'results' => $results,
        'search' => $search,
        'filters' => $filters ?? [],
        'filterColumns' => $filterColumns ?? [],
    ]) ?>
</div>
