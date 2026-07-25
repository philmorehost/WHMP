<?php
/** @var array<int, array<string, mixed>> $invoices */
?>
<style>
/* ====== Invoices Page Styles ====== */
.invoices-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 56px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 40px;
    position: relative;
    overflow: hidden;
    margin-bottom: 48px;
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
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 16px 0;
    line-height: 1.2;
}
.invoices-hero__subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,.75);
    margin: 0 0 24px 0;
    line-height: 1.6;
}
.invoices-hero__actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 20px;
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
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    flex-shrink: 0;
    border: 2px solid rgba(59,130,246,.3);
    position: relative;
    z-index: 1;
    box-shadow: 0 20px 40px rgba(59,130,246,.1);
}

/* Toolbar & Search */
.invoices-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}

/* Invoices Grid */
.invoices-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
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
    transform: translateY(-8px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 16px 32px rgba(0,0,0,0.12);
}
.invoice-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.invoice-card__number {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 1.1rem;
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
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.invoice-card__amount {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
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
    font-size: 1.25rem;
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
    padding: 16px 24px;
    background: var(--cv-bg-surface-sunken);
    border-top: 1px solid var(--cv-border-default);
    display: flex;
    gap: 8px;
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
.invoice-card__action--pay {
    background: linear-gradient(135deg, #10b981, #059669);
}
.invoice-card__action--pay:hover {
    background: linear-gradient(135deg, #059669, #047857);
    box-shadow: 0 8px 16px rgba(16,185,129,.3);
}
.invoice-card__checkbox {
    padding: 6px 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Empty State */
.empty-state-invoices {
    text-align: center;
    padding: 80px 40px;
    background: var(--cv-bg-surface);
    border-radius: 16px;
    border: 1px dashed var(--cv-border-default);
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

/* Mobile Responsive */
@media (max-width: 768px) {
    .invoices-hero {
        flex-direction: column;
        padding: 40px 24px;
        gap: 24px;
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

    <!-- Search Toolbar -->
    <div class="invoices-toolbar">
        <div style="flex: 1; min-width: 200px;">
            <?= $view->partial('partials.table-search', ['target' => '#invoices-list', 'placeholder' => 'Search invoices by number...']) ?>
        </div>
        <div style="color: var(--cv-text-secondary); font-size: .9rem;">
            <?= count($invoices) ?> invoice<?= count($invoices) !== 1 ? 's' : '' ?>
        </div>
    </div>

    <!-- Invoices Grid or Empty State -->
    <?php if ($invoices === []): ?>
        <div class="empty-state-invoices">
            <div class="empty-state-invoices__icon">📋</div>
            <h2 class="empty-state-invoices__title">No Invoices Yet</h2>
            <p class="empty-state-invoices__text">You don't have any invoices. When you purchase services, invoices will appear here.</p>
        </div>
    <?php else: ?>
        <div class="invoices-grid" id="invoices-list">
            <?php foreach ($invoices as $invoice): ?>
                <div class="invoice-card" style="<?= $invoice['status'] === 'unpaid' ? 'border-left: 4px solid #ef4444;' : 'border-left: 4px solid #10b981;' ?>">
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
                            <span class="invoice-card__amount-value"><?= e($invoice['currency']['symbol']) ?><?= number_format((float) $invoice['total'], 2) ?></span>
                        </div>

                        <div class="invoice-card__info-row">
                            <span class="invoice-card__info-label">Due Date</span>
                            <span class="invoice-card__info-value"><?= e($invoice['due_date']) ?></span>
                        </div>
                    </div>

                    <div class="invoice-card__footer">
                        <a href="/client/invoices/<?= (int) $invoice['id'] ?>" class="invoice-card__action">View Details</a>
                        <?php if ($invoice['status'] === 'unpaid'): ?>
                            <label class="invoice-card__checkbox">
                                <input type="checkbox" name="invoice_ids[]" value="<?= (int) $invoice['id'] ?>" class="inv-chk" style="cursor: pointer; width: 18px; height: 18px;">
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

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
</form>
