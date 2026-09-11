<?php
/** @var array<int, array<string, mixed>> $items */
/** @var string|null $msg */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Billable Items</h1>
    <p><a href="/admin/invoices">&larr; Back to invoices</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Ad-hoc charges queued onto a client's next invoice. Pending items can be edited, cancelled or deleted before
        the invoicing cron picks them up; an item already turned into an invoice is kept as history. Clients can also
        cancel a pending item from their own billing area.
    </p>
</div>

<?php if (!empty($msg)): ?>
    <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#059669;padding:12px 16px;border-radius:8px;margin-bottom:var(--cv-space-3);font-weight:600;">
        <?= e((string) $msg) ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#b91c1c;padding:12px 16px;border-radius:8px;margin-bottom:var(--cv-space-3);font-weight:600;">
        <?= e((string) $error) ?>
    </div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#billable-items-table', 'placeholder' => 'Search billable items...']) ?>
    </div>
    <table class="cv-table" id="billable-items-table">
        <thead><tr><th>Client</th><th>Description</th><th>Amount</th><th>Source</th><th>Status</th><th style="width:230px;text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php
            // Fall back to deriving the status from invoice_id for a row that
            // predates migration 0181's backfill.
            $status = (string) ($item['status'] ?? ($item['invoice_id'] !== null ? 'invoiced' : 'pending'));
            $isPending = $status === 'pending';
            ?>
            <tr>
                <td><?= e($item['first_name'] . ' ' . $item['last_name']) ?> (<?= e($item['client_email']) ?>)</td>
                <td>
                    <?= e($item['description']) ?>
                    <?php if ($status === 'cancelled' && !empty($item['cancelled_reason'])): ?>
                        <div style="font-size:.72rem;color:var(--cv-text-secondary);">Reason: <?= e((string) $item['cancelled_reason']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-family:'Monaco','Courier New',monospace;font-weight:700;"><?= number_format((float) $item['amount'], 2) ?></td>
                <td><?= e((string) ($item['source_type'] ?? '-')) ?><?= $item['source_id'] !== null ? ' #' . (int) $item['source_id'] : '' ?></td>
                <td>
                    <?php if ($status === 'invoiced'): ?>
                        <span class="cv-badge cv-badge--success">Invoiced<?= $item['invoice_id'] !== null ? ' (#' . (int) $item['invoice_id'] . ')' : '' ?></span>
                    <?php elseif ($status === 'cancelled'): ?>
                        <span class="cv-badge cv-badge--neutral">Cancelled<?= !empty($item['cancelled_by']) ? ' (' . e((string) $item['cancelled_by']) . ')' : '' ?></span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Pending Invoice</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <?php if ($isPending): ?>
                        <details style="display:inline-block;text-align:left;position:relative;">
                            <summary class="cv-btn cv-btn--secondary" style="display:inline-block;padding:6px 10px;font-size:.75rem;cursor:pointer;list-style:none;">✏️ Edit</summary>
                            <form method="post" action="/admin/billable-items/<?= (int) $item['id'] ?>" style="margin-top:8px;padding:12px;border:1px solid var(--cv-border-default);border-radius:8px;background:var(--cv-bg-surface);min-width:260px;text-align:left;">
                                <?= csrf_field() ?>
                                <div class="cv-field">
                                    <label class="cv-label" style="font-size:.75rem;">Description</label>
                                    <input class="cv-input" name="description" value="<?= e((string) $item['description']) ?>" required>
                                </div>
                                <div class="cv-field">
                                    <label class="cv-label" style="font-size:.75rem;">Amount</label>
                                    <input class="cv-input" type="number" step="0.01" min="0" name="amount" value="<?= e(number_format((float) $item['amount'], 2, '.', '')) ?>" required>
                                </div>
                                <button class="cv-btn" type="submit" style="padding:6px 12px;font-size:.75rem;">Save</button>
                            </form>
                        </details>
                        <form method="post" action="/admin/billable-items/<?= (int) $item['id'] ?>/cancel" style="display:inline;margin:0 0 0 4px;"
                              data-confirm="Cancel this billable item? It will not be invoiced."><?= csrf_field() ?>
                            <input type="hidden" name="reason" value="Cancelled by admin">
                            <button type="submit" class="cv-btn cv-btn--secondary" style="padding:6px 10px;font-size:.75rem;">🚫 Cancel</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/admin/billable-items/<?= (int) $item['id'] ?>/delete" style="display:inline;margin:0 0 0 4px;"
                          data-confirm="Delete this billable item permanently?<?= $item['invoice_id'] !== null ? ' It is already invoiced — the invoice itself is not affected.' : '' ?>"><?= csrf_field() ?>
                        <button type="submit" class="cv-btn cv-btn--danger" style="padding:6px 10px;font-size:.75rem;">🗑️ Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No billable items yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
