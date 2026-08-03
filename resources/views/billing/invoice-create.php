<?php
/** @var array<int, array<string, mixed>> $clients */
/** @var string|null $error */
/** @var array{client_id: string|int, due_in_days: int, items: array<int, array{description: string, amount: string}>} $old */

// Always render a few blank rows so there's something to type into; the
// controller ignores rows left empty.
$rows = $old['items'] ?? [];
while (count($rows) < 3) {
    $rows[] = ['description' => '', 'amount' => ''];
}
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Generate Invoice</h1>
    <p><a href="/admin/invoices">&larr; Back to invoices</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:0;">
        Raises an ad-hoc invoice for a client — for charges the automated paths (orders, renewals,
        billable items) don't cover. The invoice is created unpaid and the client is billed in their
        own currency.
    </p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/invoices/create"><?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label" for="invoice-client">Client</label>
                <select class="cv-select" name="client_id" id="invoice-client" required>
                    <option value="">— Select client —</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>" <?= (string) ($old['client_id'] ?? '') === (string) $client['id'] ? 'selected' : '' ?>>
                            <?= e(trim($client['first_name'] . ' ' . $client['last_name'])) ?> (<?= e($client['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cv-field">
                <label class="cv-label" for="invoice-due">Payment due in (days)</label>
                <input class="cv-input" type="number" min="0" name="due_in_days" id="invoice-due" value="<?= (int) ($old['due_in_days'] ?? 7) ?>" required>
                <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">0 makes it due today.</span>
            </div>
        </div>

        <h3 style="font-size:var(--cv-text-base);margin:var(--cv-space-3) 0 var(--cv-space-2);">Line Items</h3>

        <div data-invoice-items>
            <?php foreach ($rows as $row): ?>
                <div style="display:grid;grid-template-columns:1fr 160px 40px;gap:var(--cv-space-2);margin-bottom:var(--cv-space-2);align-items:center;" data-invoice-item-row>
                    <input class="cv-input" type="text" name="item_description[]" placeholder="Description (e.g. Custom development work)" value="<?= e((string) $row['description']) ?>">
                    <input class="cv-input" type="number" step="0.01" name="item_amount[]" placeholder="0.00" value="<?= e((string) $row['amount']) ?>">
                    <button type="button" class="cv-btn cv-btn--secondary" data-remove-invoice-item title="Remove line" style="padding:6px 10px;">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="cv-btn cv-btn--secondary" data-add-invoice-item style="margin-bottom:var(--cv-space-3);">+ Add line item</button>

        <div style="border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
            <button class="cv-btn cv-btn--primary" type="submit">Generate Invoice</button>
            <a href="/admin/invoices" class="cv-btn cv-btn--secondary">Cancel</a>
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                Total: <strong data-invoice-total>0.00</strong>
            </span>
        </div>
    </form>
</div>
