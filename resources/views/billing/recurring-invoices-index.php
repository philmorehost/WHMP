<?php
/** @var array<int, array<string, mixed>> $recurring */
/** @var array<int, array<string, mixed>> $currencies */
/** @var int|null $created */
/** @var bool $paused */
/** @var bool $resumed */
/** @var bool $cancelled */
/** @var string|null $error */
$cycleLabels = \CodeVault\Catalog\BillingCycle::labels();
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--cv-space-2);">
        <div>
            <h1 class="cv-card__title">Recurring Invoices</h1>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin:0;">
                Ad-hoc invoices set to repeat automatically. The cron raises a new invoice for each active one
                on its next due date until you pause or cancel it.
            </p>
        </div>
        <a class="cv-btn" href="/admin/invoices/create">＋ New Invoice</a>
    </div>
</div>

<?php if ($created !== null): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ✔ Recurring invoice #<?= (int) $created ?> created — the first invoice was raised now; future ones will be generated automatically.
    </div>
<?php endif; ?>
<?php if ($paused): ?>
    <div class="cv-card" style="background:rgba(245,158,11,0.1);border-color:#f59e0b;color:#d97706;margin-bottom:var(--cv-space-4);">
        ⏸ Recurring invoice paused — no further invoices will be generated until it's resumed.
    </div>
<?php endif; ?>
<?php if ($resumed): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ▶ Recurring invoice resumed.
    </div>
<?php endif; ?>
<?php if ($cancelled): ?>
    <div class="cv-card" style="background:rgba(107,114,128,0.12);border-color:#6b7280;color:#6b7280;margin-bottom:var(--cv-space-4);">
        ✖ Recurring invoice cancelled.
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="cv-card" style="background:rgba(239,68,68,0.08);border-color:#ef4444;color:#dc2626;margin-bottom:var(--cv-space-4);">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="cv-card">
    <?php if ($recurring === []): ?>
        <p style="color:var(--cv-text-secondary);text-align:center;padding:var(--cv-space-8);margin:0;">
            No recurring invoices yet. Create one by checking <strong>"Make this a recurring invoice"</strong>
            on the <a href="/admin/invoices/create">Generate Invoice</a> page.
        </p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="cv-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Cycle</th>
                        <th>Items</th>
                        <th>Next Invoice</th>
                        <th>Last Generated</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recurring as $ri): ?>
                        <?php
                        $sym = $currencies[$ri['id']]['symbol'] ?? '$';
                        $items = is_array($ri['items']) ? $ri['items'] : [];
                        $itemSummary = count($items) === 1
                            ? e((string) ($items[0]['description'] ?? 'Line item'))
                            : count($items) . ' line items';
                        $statusBadge = match ($ri['status']) {
                            'active' => '<span class="cv-badge cv-badge--success">Active</span>',
                            'paused' => '<span class="cv-badge" style="background:rgba(245,158,11,.12);color:#d97706;border:1px solid rgba(245,158,11,.3);">Paused</span>',
                            default => '<span class="cv-badge cv-badge--neutral">Cancelled</span>',
                        };
                        ?>
                        <tr>
                            <td><strong>#<?= (int) $ri['id'] ?></strong></td>
                            <td>
                                <strong><?= e(trim((string) ($ri['first_name'] ?? '') . ' ' . (string) ($ri['last_name'] ?? ''))) ?></strong>
                                <div style="font-size:.78rem;color:var(--cv-text-secondary);"><?= e((string) ($ri['client_email'] ?? '')) ?></div>
                            </td>
                            <td style="text-align:right;font-family:'Monaco','Courier New',monospace;font-weight:700;"><?= e($sym) ?><?= number_format((float) $ri['amount'], 2) ?></td>
                            <td><?= e($cycleLabels[$ri['billing_cycle']] ?? ucfirst((string) $ri['billing_cycle'])) ?></td>
                            <td style="max-width:220px;"><?= $itemSummary ?></td>
                            <td><?= e((string) $ri['next_due_date']) ?></td>
                            <td style="color:var(--cv-text-secondary);font-size:.85rem;"><?= $ri['last_generated_at'] !== null ? e((string) $ri['last_generated_at']) : '—' ?></td>
                            <td><?= $statusBadge ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <?php if ($ri['status'] === 'active'): ?>
                                    <form method="post" action="/admin/recurring-invoices/<?= (int) $ri['id'] ?>/status" style="display:inline;"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="pause">
                                        <button class="cv-btn cv-btn--secondary" type="submit" title="Pause — stop generating invoices until resumed" style="padding:4px 10px;font-size:.75rem;">Pause</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="/admin/recurring-invoices/<?= (int) $ri['id'] ?>/status" style="display:inline;"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resume">
                                        <button class="cv-btn cv-btn--secondary" type="submit" title="Resume generation" style="padding:4px 10px;font-size:.75rem;">Resume</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($ri['status'] !== 'cancelled'): ?>
                                    <form method="post" action="/admin/recurring-invoices/<?= (int) $ri['id'] ?>/status" style="display:inline;"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <button class="cv-btn" type="submit" style="background:#dc2626;color:#fff;border:none;padding:4px 10px;font-size:.75rem;" data-confirm="Permanently stop this recurring invoice? Past invoices are unaffected.">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
