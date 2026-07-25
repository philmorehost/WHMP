<?php
/** @var array<int, array<string, mixed>> $invoices */
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $pagination */
/** @var string $statusFilter */
$totalPages = max(1, (int) ceil(($pagination['total'] ?? count($invoices)) / ($pagination['perPage'] ?? 12)));
$currentPage = $pagination['page'] ?? 1;
$statusFilter = $statusFilter ?? '';
?>
<style>
/* ====== Invoices Page Styles ====== */
.invoices-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 48px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 40px;
    position: relative;
    overflow: hidden;
    margin-bottom: 32px;
    border-radius: 16px;
}
.invoices-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(59,130,246,.12) 0%, transparent 70%);
    pointer-events: none;
}
.invoices-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.invoices-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.25rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 12px 0;
    line-height: 1.2;
}
.invoices-hero__subtitle {
    font-size: 1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 16px 0;
    line-height: 1.5;
}
.invoices-hero__actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}
.invoices-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    transition: all 0.2s;
}
.invoices-hero__link:hover {
    gap: 12px;
    color: #60a5fa;
}
.invoices-hero__icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    flex-shrink: 0;
    border: 2px solid rgba(59,130,246,.3);
    position: relative;
    z-index: 1;
    box-shadow: 0 20px 40px rgba(59,130,246,.1);
}

/* Status Filter Bar */
.invoices-status-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.invoices-status-link {
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: .85rem;
    color: var(--cv-text-secondary);
    background: var(--cv-bg-surface-sunken);
    border: 1px solid var(--cv-border-default);
    transition: all 0.2s;
    white-space: nowrap;
}
.invoices-status-link:hover {
    color: var(--cv-text-primary);
    border-color: var(--cv-color-brand-500);
}
.invoices-status-link.active {
    background: var(--cv-color-brand-500);
    color: #fff;
    border-color: var(--cv-color-brand-500);
}

/* Toolbar & Search */
.invoices-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

/* Invoices Grid - Exactly 3 Columns on Desktop */
.invoices-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-bottom: 32px;
}

/* Invoice Card */
.invoice-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    height: 100%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.invoice-card:hover {
    transform: translateY(-4px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
}
.invoice-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 20px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.invoice-card__number {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0;
    line-height: 1.3;
    flex: 1;
}
.invoice-card__status {
    flex-shrink: 0;
}
.invoice-card__body {
    padding: 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.invoice-card__amount {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px;
    background: var(--cv-bg-surface-sunken);
    border-radius: 8px;
    border: 1px solid var(--cv-border-default);
}
.invoice-card__amount-label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    font-weight: 500;
}
.invoice-card__amount-value {
    font-family: 'Monaco', 'Courier New', monospace;
    color: var(--cv-color-brand-500);
    font-size: 1.15rem;
    font-weight: 800;
}
.invoice-card__info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .85rem;
}
.invoice-card__info-label {
    color: var(--cv-text-secondary);
    font-weight: 500;
}
.invoice-card__info-value {
    color: var(--cv-text-primary);
    font-weight: 600;
}
.invoice-card__footer {
    padding: 16px 20px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
    display: flex;
    gap: 8px;
    align-items: center;
}
.invoice-card__action {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    font-weight: 700;
    font-size: .8rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex: 1;
}
.invoice-card__action:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateX(2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

/* Responsive Pagination Bar */
.invoices-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    margin-top: 24px;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 16px;
}
.invoices-pagination__info {
    color: var(--cv-text-secondary);
    font-size: .875rem;
}
.invoices-pagination__controls {
    display: flex;
    align-items: center;
    gap: 6px;
}
.invoices-pagination__btn {
    padding: 6px 14px;
    border-radius: 8px;
    background: var(--cv-bg-surface-sunken);
    border: 1px solid var(--cv-border-default);
    color: var(--cv-text-primary);
    text-decoration: none;
    font-size: .85rem;
    font-weight: 600;
    transition: all .2s;
}
.invoices-pagination__btn:hover:not(.disabled) {
    border-color: var(--cv-color-brand-500);
    color: var(--cv-color-brand-500);
}
.invoices-pagination__btn.active {
    background: var(--cv-color-brand-500);
    color: #fff;
    border-color: var(--cv-color-brand-500);
}
.invoices-pagination__btn.disabled {
    opacity: 0.5;
    pointer-events: none;
    cursor: default;
}

/* Empty State */
.empty-state-invoices {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
    margin-bottom: 32px;
}
.empty-state-invoices__icon {
    font-size: 3.5rem;
    margin-bottom: 24px;
}
.empty-state-invoices__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state-invoices__text {
    color: var(--cv-text-secondary);
    font-size: 1rem;
    margin: 0;
}

/* Payment Footer */
.invoices-footer {
    display: flex;
    gap: 16px;
    align-items: center;
    padding: 16px 24px;
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    margin-top: 24px;
}
.invoices-footer__checkbox {
    flex-shrink: 0;
}
.invoices-footer__text {
    flex: 1;
    color: var(--cv-text-secondary);
    font-size: .9rem;
}
.invoices-footer__btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.invoices-footer__btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(16,185,129,.3);
}
.invoices-footer__btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Responsive Breakpoints */
@media (max-width: 1024px) {
    .invoices-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 768px) {
    .invoices-hero {
        flex-direction: column;
        padding: 32px 24px;
        gap: 20px;
    }
    .invoices-hero__title {
        font-size: 1.75rem;
    }
    .invoices-grid {
        grid-template-columns: 1fr;
    }
    .invoices-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .invoices-pagination {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .invoices-footer {
        flex-direction: column;
        align-items: stretch;
    }
    .invoices-footer__btn {
        width: 100%;
    }
}
</style>

<form method="post" action="/client/invoices/mass-pay" style="max-width: 1400px; margin: 0 auto;">
    <?= csrf_field() ?>

    <!-- Hero Section -->
    <div class="invoices-hero">
        <div class="invoices-hero__content">
            <h1 class="invoices-hero__title">My Invoices</h1>
            <p class="invoices-hero__subtitle">View and manage all your invoices, payments, and billing history</p>
            <div class="invoices-hero__actions">
                <a href="/client/dashboard" class="invoices-hero__link">
                    <span>← Back to dashboard</span>
                </a>
                <a href="/client/payment-methods" class="invoices-hero__link" style="color: #f59e0b;">
                    <span>Manage Payment Methods</span>
                    <span>→</span>
                </a>
            </div>
        </div>
        <div class="invoices-hero__icon">📄</div>
    </div>

    <!-- Status Filter Bar -->
    <div class="invoices-status-bar">
        <a href="/client/invoices" class="invoices-status-link <?= $statusFilter === '' ? 'active' : '' ?>">All Invoices</a>
        <a href="/client/invoices?status=unpaid" class="invoices-status-link <?= $statusFilter === 'unpaid' ? 'active' : '' ?>">Unpaid</a>
        <a href="/client/invoices?status=paid" class="invoices-status-link <?= $statusFilter === 'paid' ? 'active' : '' ?>">Paid</a>
        <a href="/client/invoices?status=cancelled" class="invoices-status-link <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
        <a href="/client/invoices?status=refunded" class="invoices-status-link <?= $statusFilter === 'refunded' ? 'active' : '' ?>">Refunded</a>
    </div>

    <!-- Search Toolbar & View Switcher -->
    <div class="invoices-toolbar">
        <div style="flex: 1; min-width: 200px;">
            <?= $view->partial('partials.table-search', ['target' => '#invoices-list, #invoices-table-body', 'placeholder' => 'Search invoices by number...']) ?>
        </div>
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="display: inline-flex; background: var(--cv-bg-surface-sunken); padding: 3px; border-radius: 8px; border: 1px solid var(--cv-border-default);">
                <button type="button" id="btn-view-grid" style="background: var(--cv-color-brand-500); color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: .8rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <span>田</span> Grid
                </button>
                <button type="button" id="btn-view-list" style="background: transparent; color: var(--cv-text-secondary); border: none; padding: 6px 14px; border-radius: 6px; font-size: .8rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <span>☰</span> List
                </button>
            </div>
            <div style="color: var(--cv-text-secondary); font-size: .9rem;">
                Showing <strong><?= count($invoices) ?></strong> of <strong><?= (int) ($pagination['total'] ?? count($invoices)) ?></strong> invoices
            </div>
        </div>
    </div>

    <!-- Invoices Grid / List or Empty State -->
    <?php if ($invoices === []): ?>
        <div class="empty-state-invoices">
            <div class="empty-state-invoices__icon">📋</div>
            <h2 class="empty-state-invoices__title">No Invoices Found</h2>
            <p class="empty-state-invoices__text">
                <?= $statusFilter !== '' ? 'No invoices match this status filter.' : 'You do not have any invoices yet.' ?>
            </p>
        </div>
    <?php else: ?>
        <!-- Grid Container -->
        <div id="invoices-container-grid">
            <div class="invoices-grid" id="invoices-list">
                <?php foreach ($invoices as $invoice): ?>
                    <div class="invoice-card" style="<?= $invoice['status'] === 'unpaid' ? 'border-left: 4px solid #ef4444;' : ($invoice['status'] === 'cancelled' ? 'border-left: 4px solid #6b7280;' : 'border-left: 4px solid #10b981;') ?>">
                        <div class="invoice-card__header">
                            <h3 class="invoice-card__number">INV-<?= (int) $invoice['id'] ?></h3>
                            <div class="invoice-card__status">
                                <?php if ($invoice['status'] === 'paid'): ?>
                                    <span class="cv-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Paid</span>
                                <?php elseif ($invoice['status'] === 'cancelled'): ?>
                                    <span class="cv-badge" style="background: linear-gradient(135deg, #6b7280, #4b5563); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Cancelled</span>
                                <?php else: ?>
                                    <span class="cv-badge" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 6px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; text-transform: uppercase;">Unpaid</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="invoice-card__body">
                            <div class="invoice-card__amount">
                                <span class="invoice-card__amount-label">Amount Due</span>
                                <span class="invoice-card__amount-value"><?= e($invoice['currency']['symbol']) ?><?= number_format(round((float) $invoice['total'] * ((float) $invoice['currency_rate'] > 0 ? (float) $invoice['currency_rate'] : 1.0), 2), 2) ?></span>
                            </div>

                            <div class="invoice-card__info-row">
                                <span class="invoice-card__info-label">Due Date</span>
                                <span class="invoice-card__info-value"><?= e($invoice['due_date']) ?></span>
                            </div>
                        </div>

                        <div class="invoice-card__footer">
                            <a href="/client/invoices/<?= (int) $invoice['id'] ?>" class="invoice-card__action">View Details</a>
                            <?php if ($invoice['status'] === 'unpaid'): ?>
                                <button type="submit" form="cancel-form-<?= (int) $invoice['id'] ?>" class="invoice-card__action" style="background: #dc2626; flex: 0 0 auto;" onclick="return confirm('Are you sure you want to cancel Invoice #INV-<?= (int) $invoice['id'] ?>?');">Cancel</button>
                                <label class="invoice-card__checkbox">
                                    <input type="checkbox" name="invoice_ids[]" value="<?= (int) $invoice['id'] ?>" class="inv-chk" style="cursor: pointer; width: 18px; height: 18px;">
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Table / List Container -->
        <div id="invoices-container-list" style="display: none; margin-bottom: 32px; background: var(--cv-bg-surface); border: 1px solid var(--cv-border-default); border-radius: 12px; overflow: hidden;">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; text-align: left;">
                    <thead>
                        <tr style="background: var(--cv-bg-surface-sunken); border-bottom: 1px solid var(--cv-border-default); color: var(--cv-text-secondary); font-weight: 700; font-size: 0.8rem; text-transform: uppercase;">
                            <th style="padding: 14px 16px; width: 40px; text-align: center;"></th>
                            <th style="padding: 14px 16px;">Invoice #</th>
                            <th style="padding: 14px 16px;">Status</th>
                            <th style="padding: 14px 16px;">Due Date</th>
                            <th style="padding: 14px 16px; text-align: right;">Amount Due</th>
                            <th style="padding: 14px 16px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoices-table-body">
                        <?php foreach ($invoices as $invoice): ?>
                            <tr style="border-bottom: 1px solid var(--cv-border-default);">
                                <td style="padding: 14px 16px; text-align: center;">
                                    <?php if ($invoice['status'] === 'unpaid'): ?>
                                        <input type="checkbox" name="invoice_ids[]" value="<?= (int) $invoice['id'] ?>" class="inv-chk" style="cursor: pointer; width: 16px; height: 16px;">
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; font-weight: 700; font-family: monospace;">
                                    <a href="/client/invoices/<?= (int) $invoice['id'] ?>" style="color: var(--cv-color-brand-500); text-decoration: none;">INV-<?= (int) $invoice['id'] ?></a>
                                </td>
                                <td style="padding: 14px 16px;">
                                    <?php if ($invoice['status'] === 'paid'): ?>
                                        <span style="color: #10b981; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; background: rgba(16,185,129,0.1); padding: 4px 8px; border-radius: 4px;">Paid</span>
                                    <?php elseif ($invoice['status'] === 'cancelled'): ?>
                                        <span style="color: #6b7280; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; background: rgba(107,114,128,0.1); padding: 4px 8px; border-radius: 4px;">Cancelled</span>
                                    <?php else: ?>
                                        <span style="color: #ef4444; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; background: rgba(239,68,68,0.1); padding: 4px 8px; border-radius: 4px;">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; color: var(--cv-text-secondary);"><?= e($invoice['due_date']) ?></td>
                                <td style="padding: 14px 16px; text-align: right; font-weight: 700; font-family: monospace;">
                                    <?= e($invoice['currency']['symbol']) ?><?= number_format(round((float) $invoice['total'] * ((float) $invoice['currency_rate'] > 0 ? (float) $invoice['currency_rate'] : 1.0), 2), 2) ?>
                                </td>
                                <td style="padding: 14px 16px; text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                        <a href="/client/invoices/<?= (int) $invoice['id'] ?>" class="cv-btn cv-btn--secondary" style="padding: 4px 10px; font-size: 0.75rem; text-decoration: none;">View</a>
                                        <?php if ($invoice['status'] === 'unpaid'): ?>
                                            <button type="submit" form="cancel-form-<?= (int) $invoice['id'] ?>" class="cv-btn" style="background: #dc2626; color: #fff; border: none; padding: 4px 10px; font-size: 0.75rem; border-radius: 4px; cursor: pointer;" onclick="return confirm('Are you sure you want to cancel Invoice #INV-<?= (int) $invoice['id'] ?>?');">Cancel</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 100% Responsive Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <?php
            $queryBase = '/client/invoices?' . ($statusFilter !== '' ? 'status=' . urlencode($statusFilter) . '&' : '');
            ?>
            <div class="invoices-pagination">
                <div class="invoices-pagination__info">
                    Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong> (Total <strong><?= number_format((int) ($pagination['total'] ?? 0)) ?></strong> invoices)
                </div>
                <div class="invoices-pagination__controls">
                    <!-- First Page -->
                    <a class="invoices-pagination__btn <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= $queryBase ?>page=1">« First</a>
                    
                    <!-- Previous Page -->
                    <a class="invoices-pagination__btn <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= $queryBase ?>page=<?= max(1, $currentPage - 1) ?>">← Prev</a>

                    <!-- Numbered Pages -->
                    <?php
                    $startP = max(1, $currentPage - 2);
                    $endP = min($totalPages, $currentPage + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a class="invoices-pagination__btn <?= $p === $currentPage ? 'active' : '' ?>" href="<?= $queryBase ?>page=<?= $p ?>"><?= $p ?></a>
                    <?php endfor; ?>

                    <!-- Next Page -->
                    <a class="invoices-pagination__btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= $queryBase ?>page=<?= min($totalPages, $currentPage + 1) ?>">Next →</a>

                    <!-- Last Page -->
                    <a class="invoices-pagination__btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= $queryBase ?>page=<?= $totalPages ?>">Last »</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pay Selected Invoices Footer -->
        <?php $unpaidCount = count(array_filter($invoices, fn ($inv) => $inv['status'] === 'unpaid')); ?>
        <?php if ($unpaidCount > 0): ?>
            <div class="invoices-footer">
                <label class="invoices-footer__checkbox">
                    <input type="checkbox" id="select-all-invoices" onclick="document.querySelectorAll('.inv-chk').forEach(c => c.checked = this.checked); updatePayButton()" style="cursor: pointer; width: 18px; height: 18px;">
                </label>
                <span class="invoices-footer__text" id="selected-count">0 unpaid invoices selected</span>
                <button class="invoices-footer__btn" type="submit" id="pay-btn" disabled>Pay Selected Invoices →</button>
            </div>

            <script>
                function updatePayButton() {
                    const checkboxes = document.querySelectorAll('.inv-chk');
                    const selected = Array.from(checkboxes).filter(c => c.checked).length;
                    const countEl = document.getElementById('selected-count');
                    const btn = document.getElementById('pay-btn');
                    countEl.textContent = selected + ' ' + (selected !== 1 ? 'invoices' : 'invoice') + ' selected';
                    btn.disabled = selected === 0;
                }
                document.querySelectorAll('.inv-chk').forEach(cb => cb.addEventListener('change', updatePayButton));
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Hidden forms for direct invoice cancellation -->
    <?php foreach ($invoices as $invoice): ?>
        <?php if ($invoice['status'] === 'unpaid'): ?>
            <form id="cancel-form-<?= (int) $invoice['id'] ?>" method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/cancel" style="display:none;">
                <?= csrf_field() ?>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
</form>

<script>
    (function () {
        const btnGrid = document.getElementById('btn-view-grid');
        const btnList = document.getElementById('btn-view-list');
        const gridContainer = document.getElementById('invoices-container-grid');
        const listContainer = document.getElementById('invoices-container-list');

        if (!btnGrid || !btnList || !gridContainer || !listContainer) {
            return;
        }

        function setViewMode(mode) {
            if (mode === 'list') {
                gridContainer.style.display = 'none';
                listContainer.style.display = 'block';
                btnList.style.background = 'var(--cv-color-brand-500)';
                btnList.style.color = '#fff';
                btnGrid.style.background = 'transparent';
                btnGrid.style.color = 'var(--cv-text-secondary)';
                localStorage.setItem('invoices_view_mode', 'list');
            } else {
                gridContainer.style.display = 'block';
                listContainer.style.display = 'none';
                btnGrid.style.background = 'var(--cv-color-brand-500)';
                btnGrid.style.color = '#fff';
                btnList.style.background = 'transparent';
                btnList.style.color = 'var(--cv-text-secondary)';
                localStorage.setItem('invoices_view_mode', 'grid');
            }
        }

        btnGrid.addEventListener('click', function (e) {
            e.preventDefault();
            setViewMode('grid');
        });

        btnList.addEventListener('click', function (e) {
            e.preventDefault();
            setViewMode('list');
        });

        if (localStorage.getItem('invoices_view_mode') === 'list') {
            setViewMode('list');
        }
    })();
</script>
