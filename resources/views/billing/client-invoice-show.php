<?php
/** @var array<string, mixed> $invoice */
/** @var array<int, array<string, mixed>> $items */
/** @var array<int, array<string, mixed>> $transactions */
/** @var array<int, array<string, mixed>> $gateways */
/** @var float $creditBalance */
/** @var array<string, mixed> $currency */
/** @var string|null $paymentStatus */
$rate = (float) $invoice['currency_rate'];
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * $rate, 2);
$paymentStatus ??= null;
?>
<div style="max-width:54rem; margin:0 auto; padding:0 var(--cv-space-4);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--cv-space-4); flex-wrap:wrap; gap:var(--cv-space-2);">
        <p><a href="/client/invoices" style="text-decoration:none; font-weight:600; color:var(--cv-color-brand-500);">&larr; Back to Invoices</a></p>
        <div style="display:flex; gap:var(--cv-space-2); align-items:center;">
            <span style="font-size:var(--cv-text-sm); color:var(--cv-text-secondary);">Wallet Balance: <strong><?= $money($creditBalance) ?></strong></span>
            <a href="/client/wallet/add-funds" class="cv-btn cv-btn--secondary" style="padding:4px 10px; font-size:var(--cv-text-xs); text-decoration:none; display:inline-block;">+ Add Funds</a>
            <a class="cv-btn cv-btn--secondary" href="/client/invoices/<?= (int) $invoice['id'] ?>/pdf" target="_blank" style="padding:4px 10px; font-size:var(--cv-text-xs); text-decoration:none; display:inline-block;">🖨️ Print / PDF</a>
        </div>
    </div>

    <?php if ($paymentStatus === 'success'): ?>
        <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); color: #065f46; padding: var(--cv-space-3); border-radius: var(--cv-radius-md); margin-bottom: var(--cv-space-4); font-size: var(--cv-text-sm);">
            ✓ Payment received — thank you.
        </div>
    <?php elseif ($paymentStatus === 'failed'): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($_GET['msg'] ?? 'Payment was not completed. You can try again below.') ?></div>
    <?php elseif ($paymentStatus === 'error'): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($_GET['msg'] ?? "Couldn't start the payment — please try again.") ?></div>
    <?php endif; ?>

    <!-- Main Invoice Document -->
    <div class="cv-card" style="padding:var(--cv-space-6); border-radius:12px; background:#fff; color:#1f2937; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); border:1px solid var(--cv-border-default);">
        <!-- Invoice Header -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #f3f4f6; padding-bottom:var(--cv-space-4); margin-bottom:var(--cv-space-5); flex-wrap:wrap; gap:var(--cv-space-4);">
            <div>
                <h1 style="margin:0; font-family:'Hanken Grotesk',sans-serif; font-size:1.75rem; font-weight:800; color:#111827;">CodeVault</h1>
                <p style="margin:4px 0 0 0; color:#6b7280; font-size:var(--cv-text-sm);">Proforma Invoice #<?= (int) $invoice['id'] ?></p>
            </div>
            <div style="text-align:right;">
                <?php if ($invoice['status'] === 'paid'): ?>
                    <span style="font-size:1.5rem; font-weight:900; color:#10b981; text-transform:uppercase; letter-spacing:0.05em; display:block;">PAID</span>
                <?php else: ?>
                    <span style="font-size:1.5rem; font-weight:900; color:#ef4444; text-transform:uppercase; letter-spacing:0.05em; display:block;">UNPAID</span>
                <?php endif; ?>
                <p style="margin:4px 0 0 0; color:#6b7280; font-size:var(--cv-text-sm);">Due Date: <?= e($invoice['due_date']) ?></p>
            </div>
        </div>

        <!-- Billing Info -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:var(--cv-space-4); margin-bottom:var(--cv-space-6); font-size:var(--cv-text-sm);">
            <div>
                <strong style="color:#4b5563; text-transform:uppercase; font-size:var(--cv-text-xs); display:block; margin-bottom:6px;">Invoiced To</strong>
                <span style="font-weight:700; color:#111827; display:block; margin-bottom:2px;"><?= e(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')) ?></span>
                <span style="color:#4b5563; display:block;"><?= e((string) ($client['company_name'] ?? '')) ?></span>
                <span style="color:#6b7280; display:block; font-size:var(--cv-text-xs); margin-top:4px;"><?= e($client['email']) ?></span>
            </div>
            <div>
                <strong style="color:#4b5563; text-transform:uppercase; font-size:var(--cv-text-xs); display:block; margin-bottom:6px;">Pay To</strong>
                <span style="font-weight:700; color:#111827; display:block; margin-bottom:2px;"><?= e($companyName ?? 'Your Company') ?></span>
                <span style="color:#4b5563; display:block;"><?= e($companyDept ?? 'Payments Dept.') ?></span>
                <span style="color:#6b7280; display:block; font-size:var(--cv-text-xs); margin-top:4px;"><?= e($companyEmail ?? 'billing@example.com') ?></span>
            </div>
            <div>
                <strong style="color:#4b5563; text-transform:uppercase; font-size:var(--cv-text-xs); display:block; margin-bottom:6px;">Invoice Details</strong>
                <span style="color:#4b5563; display:block;">Invoice Date: <?= e(substr((string)$invoice['created_at'], 0, 10)) ?></span>
                <span style="color:#4b5563; display:block;">Payment Method: <?= e(ucfirst((string)($invoice['payment_method'] ?? 'None'))) ?></span>
            </div>
        </div>

        <!-- Apply Credit Panel -->
        <?php if ($invoice['status'] === 'unpaid' && $creditBalance > 0): ?>
            <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); padding: var(--cv-space-4); border-radius: 8px; margin-bottom: var(--cv-space-6);">
                <strong style="color:#065f46; font-size:var(--cv-text-sm); display:block; margin-bottom:4px;">Apply Credit</strong>
                <p style="color:#047857; font-size:var(--cv-text-xs); margin:0 0 var(--cv-space-3) 0;">Your credit balance is <strong><?= $money($creditBalance) ?></strong>. This can be applied to the invoice using the form below. Enter the amount to apply:</p>
                <form method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/apply-credit" style="display:flex; gap:var(--cv-space-2); max-width:300px; margin:0;">
                    <?= csrf_field() ?>
                    <input class="cv-input" type="number" step="0.01" max="<?= $creditBalance ?>" name="amount" value="<?= number_format($creditBalance, 2, '.', '') ?>" style="flex:1; padding:6px 12px; font-size:var(--cv-text-xs); border:1px solid #10b981;" required>
                    <button class="cv-btn" type="submit" style="background:#10b981; color:#fff; border:none; padding:6px 12px; font-size:var(--cv-text-xs); font-weight:700;">Apply Credit</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Invoice Items Table -->
        <h3 style="font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md); margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:8px; color:#111827;">Invoice Items</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left; font-size:var(--cv-text-sm); margin-bottom:var(--cv-space-6);">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb; color:#4b5563; font-weight:700;">
                    <th style="padding:8px 0;">Description</th>
                    <th style="padding:8px 0; text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:10px 0; color:#1f2937;"><?= e($item['description']) ?></td>
                        <td style="padding:10px 0; text-align:right; font-weight:600; color:#111827;"><?= $money((float) $item['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td style="padding:8px 0; text-align:right; color:#4b5563; font-weight:600;">Sub Total</td>
                    <td style="padding:8px 0; text-align:right; font-weight:700; color:#111827;"><?= $money((float) $invoice['subtotal']) ?></td>
                </tr>
                <?php if ((float)$invoice['discount_amount'] > 0): ?>
                    <tr>
                        <td style="padding:8px 0; text-align:right; color:#10b981; font-weight:600;">Promo Discount</td>
                        <td style="padding:8px 0; text-align:right; font-weight:700; color:#10b981;">-<?= $money((float) $invoice['discount_amount']) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ((float)$invoice['tax_amount'] > 0): ?>
                    <tr>
                        <td style="padding:8px 0; text-align:right; color:#4b5563; font-weight:600;">Tax</td>
                        <td style="padding:8px 0; text-align:right; font-weight:700; color:#111827;"><?= $money((float) $invoice['tax_amount']) ?></td>
                    </tr>
                <?php endif; ?>
                <tr style="border-top:2px solid #e5e7eb;">
                    <td style="padding:12px 0; text-align:right; font-size:var(--cv-text-md); font-weight:800; color:#111827;">Total Due</td>
                    <td style="padding:12px 0; text-align:right; font-size:var(--cv-text-md); font-weight:800; color:var(--cv-color-brand-500);"><?= $money((float) $invoice['total']) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Payment Methods & Gateways -->
        <?php if ($invoice['status'] === 'unpaid'): ?>
            <h3 style="font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md); margin-top:var(--cv-space-6); border-bottom:1px solid #e5e7eb; padding-bottom:8px; color:#111827;">Choose Payment Method</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:var(--cv-space-4); margin-top:var(--cv-space-4);">
                <?php foreach ($gateways as $gateway): ?>
                    <?php if ($gateway['slug'] === 'manual'): ?>
                        <div style="border:1px solid #e5e7eb; border-radius:8px; padding:var(--cv-space-4); background:#f9fafb;">
                            <strong style="color:#111827; display:block; margin-bottom:6px;"><?= e($gateway['name']) ?></strong>
                            <?php $config = json_decode((string) ($gateway['config'] ?? '{}'), true) ?: []; ?>
                            <p style="color:#4b5563; font-size:var(--cv-text-xs); white-space:pre-line; margin:0 0 var(--cv-space-3) 0; line-height:1.4;"><?= e($config['bank_details'] ?? 'Contact us for bank transfer details, then we will confirm your payment.') ?></p>
                            <p style="color:#6b7280; font-size:var(--cv-text-2xs); margin:0;">Once transfer is made, contact support to confirm.</p>
                        </div>
                    <?php elseif (in_array($gateway['slug'], ['paystack', 'flutterwave', 'payhub', 'plisio', 'paypal'], true)): ?>
                        <div style="border:1px solid #e5e7eb; border-radius:8px; padding:var(--cv-space-4); background:#f9fafb; display:flex; flex-direction:column; justify-content:space-between;">
                            <div>
                                <strong style="color:#111827; display:block; margin-bottom:4px;"><?= e($gateway['name']) ?></strong>
                                <p style="color:#6b7280; font-size:var(--cv-text-xs); margin:0 0 var(--cv-space-4) 0;">Secure instant online payment processing.</p>
                            </div>
                            <form method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/pay/<?= e($gateway['slug']) ?>" style="margin:0;">
                                <?= csrf_field() ?>
                                <button class="cv-btn" type="submit" style="width:100%; border-radius:6px; padding:8px; font-size:var(--cv-text-xs); font-weight:700; background:var(--cv-color-brand-500); color:#fff;">Pay with <?= e($gateway['name']) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Transactions Ledger -->
        <?php if ($transactions !== []): ?>
            <h3 style="font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md); margin-top:var(--cv-space-6); border-bottom:1px solid #e5e7eb; padding-bottom:8px; color:#111827;">Related Transactions</h3>
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:var(--cv-text-xs);">
                <thead>
                    <tr style="border-bottom:1px solid #e5e7eb; color:#6b7280;">
                        <th style="padding:6px 0;">Transaction Date</th>
                        <th style="padding:6px 0;">Gateway</th>
                        <th style="padding:6px 0; text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 0; color:#4b5563;"><?= e($tx['created_at']) ?></td>
                            <td style="padding:8px 0; color:#4b5563;"><?= e(ucfirst((string)$tx['gateway_slug'])) ?></td>
                            <td style="padding:8px 0; text-align:right; font-weight:600; color:#111827;"><?= $money((float) $tx['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
