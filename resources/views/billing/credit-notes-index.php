<?php
/** @var array<int, array<string, mixed>> $creditNotes */
?>
<style>
/* Admin Credit Notes List Styles */
.admin-cn-list-hero {
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
.admin-cn-list-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-cn-list-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.admin-cn-list-hero__back {
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
.admin-cn-list-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-cn-list-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}
.admin-cn-list-btn-create {
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
.admin-cn-list-btn-create:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Toolbar */
.admin-cn-list-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
}
.admin-cn-list-toolbar > div {
    flex: 1;
    min-width: 250px;
}

/* Table */
.admin-cn-list-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.admin-cn-list-table-wrapper {
    overflow-x: auto;
}
.admin-cn-list-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-cn-list-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-cn-list-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-cn-list-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-cn-list-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-cn-list-table td {
    padding: 16px;
    color: var(--cv-text-primary);
}
.admin-cn-list-table a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
}
.admin-cn-list-table a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Button */
.admin-cn-list-btn {
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
.admin-cn-list-btn:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}

/* Empty State */
.admin-cn-list-empty {
    padding: 80px 40px;
    text-align: center;
}
.admin-cn-list-empty__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}
.admin-cn-list-empty__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.admin-cn-list-empty__text {
    color: var(--cv-text-secondary);
    margin: 0;
}

@media (max-width: 768px) {
    .admin-cn-list-hero {
        flex-direction: column;
        padding: 32px 24px;
        align-items: flex-start;
    }
    .admin-cn-list-hero__title {
        font-size: 1.5rem;
    }
    .admin-cn-list-btn-create {
        width: 100%;
        justify-content: center;
    }
    .admin-cn-list-toolbar {
        flex-direction: column;
    }
    .admin-cn-list-toolbar > div {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-cn-list-hero">
    <div class="admin-cn-list-hero__content">
        <a href="/admin" class="admin-cn-list-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-cn-list-hero__title">💳 Credit Notes</h1>
    </div>
    <a href="/admin/credit-notes/create" class="admin-cn-list-btn-create">➕ Issue Credit Note</a>
</div>

<!-- Search Toolbar -->
<div class="admin-cn-list-toolbar">
    <div>
        <?= $view->partial('partials.table-search', ['target' => '#credit-notes-table', 'placeholder' => 'Search by credit note ID, client, or reason...']) ?>
    </div>
    <div style="flex:0 0 auto;min-width:0;">
        <?= $view->partial('partials.table-filter', [
            'formId' => 'credit-notes-filter',
            'action' => '/admin/credit-notes',
            'filters' => $filters ?? [],
            'preserve' => [],
            'activeCount' => count($filters ?? []),
        ]) ?>
    </div>
</div>

<!-- Credit Notes Table -->
<div class="admin-cn-list-card">
    <?php if ($creditNotes === []): ?>
        <div class="admin-cn-list-empty">
            <div class="admin-cn-list-empty__icon">💳</div>
            <h2 class="admin-cn-list-empty__title">No Credit Notes Found</h2>
            <p class="admin-cn-list-empty__text">No credit notes have been issued yet. <a href="/admin/credit-notes/create" style="color:var(--cv-color-brand-500);text-decoration:underline;">Issue one now</a>.</p>
        </div>
    <?php else: ?>
        <div class="admin-cn-list-table-wrapper">
            <table class="admin-cn-list-table" id="credit-notes-table">
                <thead>
                    <tr>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="id">Credit Note ID</th>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="client">Client</th>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="reason">Reason</th>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="total" style="text-align:right;">Total</th>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="invoice">Related Invoice</th>
                        <th data-col-filter="credit-notes-filter" data-col-filter-key="issued">Issued</th>
                        <th style="width:80px;"></th>
                    </tr>
                    <?= $view->partial('partials.table-filter-row', [
                        'formId' => 'credit-notes-filter',
                        'action' => '/admin/credit-notes',
                        'columns' => $filterColumns ?? [],
                        'filters' => $filters ?? [],
                        'preserve' => [],
                    ]) ?>
                </thead>
                <tbody>
                <?php foreach ($creditNotes as $note): ?>
                    <tr>
                        <td><a href="/admin/credit-notes/<?= (int) $note['id'] ?>"><strong>CN-<?= (int) $note['id'] ?></strong></a></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <strong><?= e($note['first_name'] . ' ' . $note['last_name']) ?></strong>
                                <span style="font-size:.8rem;color:var(--cv-text-secondary);"><?= e($note['client_email']) ?></span>
                            </div>
                        </td>
                        <td><?= e($note['reason']) ?></td>
                        <td style="text-align:right;font-family:'Monaco','Courier New',monospace;font-weight:700;"><?= e($note['currency_symbol'] ?? '$') ?><?= number_format((float) $note['total'], 2) ?></td>
                        <td><?= $note['invoice_id'] !== null ? '<a href="/admin/invoices/' . (int) $note['invoice_id'] . '">INV-' . (int) $note['invoice_id'] . '</a>' : '&mdash;' ?></td>
                        <td style="font-size:.85rem;color:var(--cv-text-secondary);"><?= e($note['created_at']) ?></td>
                        <td><a class="admin-cn-list-btn" href="/admin/credit-notes/<?= (int) $note['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->partial('partials.table-pagination', [
            'results' => $results ?? ['data' => [], 'total' => 0, 'page' => 1, 'perPage' => 15],
            'action' => '/admin/credit-notes',
            'filters' => $filters ?? [],
            'preserve' => [],
            'label' => 'credit notes',
        ]) ?>
    <?php endif; ?>
</div>
