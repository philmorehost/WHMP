<?php
/** @var array<int, array<string, mixed>> $quotes */
$badgeClass = static fn (string $status): string => match ($status) {
    'accepted' => 'admin-quotes-badge--accepted',
    'declined', 'expired' => 'admin-quotes-badge--declined',
    'sent' => 'admin-quotes-badge--sent',
    default => 'admin-quotes-badge--draft',
};
?>
<style>
/* Admin Quotes List Styles */
.admin-quotes-hero {
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
.admin-quotes-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-quotes-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.admin-quotes-hero__back {
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
.admin-quotes-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-quotes-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}
.admin-quotes-btn-create {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-quotes-btn-create:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Toolbar */
.admin-quotes-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
}
.admin-quotes-toolbar > div {
    flex: 1;
    min-width: 250px;
}

/* Table */
.admin-quotes-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-quotes-table-wrapper {
    overflow-x: auto;
}
.admin-quotes-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-quotes-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-quotes-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-quotes-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-quotes-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-quotes-table td {
    padding: 16px;
    color: var(--cv-text-primary);
}
.admin-quotes-table a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
}
.admin-quotes-table a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Badge */
.admin-quotes-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-quotes-badge--accepted {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-quotes-badge--declined {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-quotes-badge--sent {
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    color: #3b82f6;
    border: 1px solid rgba(59,130,246,.3);
}
.admin-quotes-badge--draft {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}

/* Button */
.admin-quotes-btn {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
    white-space: nowrap;
}
.admin-quotes-btn:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}

/* Empty State */
.admin-quotes-empty {
    padding: 80px 40px;
    text-align: center;
}
.admin-quotes-empty__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.admin-quotes-empty__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.admin-quotes-empty__text {
    color: var(--cv-text-secondary);
    margin: 0;
}

@media (max-width: 768px) {
    .admin-quotes-hero {
        flex-direction: column;
        padding: 32px 24px;
        align-items: flex-start;
    }
    .admin-quotes-hero__title {
        font-size: 1.5rem;
    }
    .admin-quotes-btn-create {
        width: 100%;
        justify-content: center;
    }
    .admin-quotes-toolbar {
        flex-direction: column;
    }
    .admin-quotes-toolbar > div {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-quotes-hero">
    <div class="admin-quotes-hero__content">
        <a href="/admin" class="admin-quotes-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-quotes-hero__title">📄 Quotes</h1>
    </div>
    <a href="/admin/quotes/create" class="admin-quotes-btn-create">➕ Create Quote</a>
</div>

<!-- Search Toolbar -->
<div class="admin-quotes-toolbar">
    <div>
        <?= $view->partial('partials.table-search', ['target' => '#quotes-table', 'placeholder' => 'Search by quote ID, client name, or subject...']) ?>
    </div>
    <div style="flex:0 0 auto;min-width:0;">
        <?= $view->partial('partials.table-filter', [
            'formId' => 'quotes-filter',
            'action' => '/admin/quotes',
            'filters' => $filters ?? [],
            'preserve' => [],
            'sort' => $sort ?? null,
            'activeCount' => count($filters ?? []),
        ]) ?>
    </div>
</div>

<!-- Quotes Table -->
<div class="admin-quotes-card">
    <?php if ($quotes === []): ?>
        <div class="admin-quotes-empty">
            <div class="admin-quotes-empty__icon">📄</div>
            <h2 class="admin-quotes-empty__title">No Quotes Found</h2>
            <p class="admin-quotes-empty__text">No quotes have been created yet. <a href="/admin/quotes/create" style="color:var(--cv-color-brand-500);text-decoration:underline;">Create one now</a>.</p>
        </div>
    <?php else: ?>
        <div class="admin-quotes-table-wrapper">
            <table class="admin-quotes-table" id="quotes-table">
                <thead>
                    <tr>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'id', 'label' => 'Quote ID', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'client', 'label' => 'Client', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'subject', 'label' => 'Subject', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'status', 'label' => 'Status', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <?= $view->partial('partials.table-header-sort', ['key' => 'valid_until', 'label' => 'Valid Until', 'action' => '/admin/quotes', 'filters' => $filters ?? [], 'preserve' => [], 'sort' => $sort ?? null]) ?>
                        <th style="width:80px;"></th>
                    </tr>
                    <?= $view->partial('partials.table-filter-row', [
                        'formId' => 'quotes-filter',
                        'action' => '/admin/quotes',
                        'columns' => $filterColumns ?? [],
                        'filters' => $filters ?? [],
                        'preserve' => [],
                    ]) ?>
                </thead>
                <tbody>
                <?php foreach ($quotes as $quote): ?>
                    <tr>
                        <td><a href="/admin/quotes/<?= (int) $quote['id'] ?>"><strong>Q-<?= (int) $quote['id'] ?></strong></a></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <strong><?= e($quote['first_name'] . ' ' . $quote['last_name']) ?></strong>
                                <span style="font-size:.8rem;color:var(--cv-text-secondary);"><?= e($quote['client_email']) ?></span>
                            </div>
                        </td>
                        <td><?= e($quote['subject']) ?></td>
                        <td style="text-align:right;font-family:'Monaco','Courier New',monospace;font-weight:700;"><?= e($quote['currency_symbol'] ?? '$') ?><?= number_format((float) $quote['total'], 2) ?></td>
                        <td><span class="admin-quotes-badge <?= $badgeClass((string) $quote['status']) ?>"><?= e(ucfirst((string) $quote['status'])) ?></span></td>
                        <td><?= $quote['valid_until'] !== null ? e((string) $quote['valid_until']) : '&mdash;' ?></td>
                        <td><a class="admin-quotes-btn" href="/admin/quotes/<?= (int) $quote['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->partial('partials.table-pagination', [
            'results' => $results ?? ['data' => [], 'total' => 0, 'page' => 1, 'perPage' => 15],
            'action' => '/admin/quotes',
            'filters' => $filters ?? [],
            'preserve' => [],
            'sort' => $sort ?? null,
            'label' => 'quotes',
        ]) ?>
    <?php endif; ?>
</div>
