<?php
/** @var array<int, array<string, mixed>> $clients */
/** @var string|null $error */
/** @var array<string, string> $billingCycles */
/** @var array{client_id: string|int, due_in_days: int, items: array<int, array{description: string, amount: string}>, is_recurring: bool, billing_cycle: string, next_due_date: string, send_invoice_email: bool, send_receipt_email: bool} $old */

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

        <!-- Optional recurring billing (WHMCS-style): raise the same line
             items again every cycle until paused/cancelled. -->
        <div style="margin-bottom:var(--cv-space-3);padding:var(--cv-space-3);border:1px solid var(--cv-border-default);border-radius:8px;">
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                <input type="checkbox" name="is_recurring" value="1" id="invoice-recurring" <?= !empty($old['is_recurring']) ? 'checked' : '' ?>>
                Make this a recurring invoice
            </label>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin:var(--cv-space-1) 0 0 var(--cv-space-6);">
                The first invoice is created now; the same line items are re-invoiced automatically each
                cycle until you pause or cancel it.
            </p>

            <div id="recurring-options" style="display:<?= !empty($old['is_recurring']) ? 'grid' : 'none' ?>;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--cv-space-3);margin-top:var(--cv-space-3);">
                <div class="cv-field">
                    <label class="cv-label" for="invoice-cycle">Billing cycle</label>
                    <select class="cv-select" name="billing_cycle" id="invoice-cycle">
                        <?php foreach (($billingCycles ?? []) as $cycleKey => $cycleLabel): ?>
                            <option value="<?= e($cycleKey) ?>" <?= ($old['billing_cycle'] ?? 'monthly') === $cycleKey ? 'selected' : '' ?>><?= e($cycleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cv-field">
                    <label class="cv-label" for="invoice-next-due">Next invoice date</label>
                    <input class="cv-input" type="date" name="next_due_date" id="invoice-next-due" value="<?= e((string) ($old['next_due_date'] ?? '')) ?>">
                    <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">Leave blank for one cycle from today.</span>
                </div>
            </div>
        </div>

        <script nonce="<?= csp_nonce() ?>">
            document.addEventListener('DOMContentLoaded', function () {
                var toggle = document.getElementById('invoice-recurring');
                var options = document.getElementById('recurring-options');
                if (toggle && options) {
                    toggle.addEventListener('change', function () {
                        options.style.display = toggle.checked ? 'grid' : 'none';
                    });
                }
            });
        </script>

        <!-- Notifications: the admin decides whether the client is emailed the
             invoice and/or a payment receipt when it's generated. -->
        <div style="margin-bottom:var(--cv-space-3);padding:var(--cv-space-3);border:1px solid var(--cv-border-default);border-radius:8px;">
            <h3 style="font-size:var(--cv-text-base);margin:0 0 var(--cv-space-2);">📧 Notify the client</h3>
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;margin-bottom:var(--cv-space-2);">
                <input type="checkbox" name="send_invoice_email" value="1" id="invoice-email-client" <?= !empty($old['send_invoice_email']) ? 'checked' : '' ?>>
                Email the invoice to the client
            </label>
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                <input type="checkbox" name="send_receipt_email" value="1" id="invoice-email-receipt" <?= !empty($old['send_receipt_email']) ? 'checked' : '' ?>>
                Also email a payment receipt to the client
            </label>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin:var(--cv-space-1) 0 0 var(--cv-space-6);">
                Uncheck both to create the invoice silently — you can still share it manually from the invoice page.
            </p>
        </div>

        <div style="border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
            <button class="cv-btn cv-btn--primary" type="submit">Generate Invoice</button>
            <a href="/admin/invoices" class="cv-btn cv-btn--secondary">Cancel</a>
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                Total: <strong data-invoice-total>0.00</strong>
            </span>
        </div>
    </form>
</div>
