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
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Invoice INV-<?= (int) $invoice['id'] ?></h1>
    <p><a href="/client/invoices">&larr; Back to invoices</a> &middot; <a href="/client/invoices/<?= (int) $invoice['id'] ?>/pdf" target="_blank">Download PDF</a></p>

    <?php if ($paymentStatus === 'success'): ?>
        <div class="cv-card" style="margin-bottom:var(--cv-space-3);background:var(--cv-color-brand-50);">
            <p style="color:var(--cv-color-success-600, #1a7f37);">Payment received — thank you.</p>
        </div>
    <?php elseif ($paymentStatus === 'failed'): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);">Payment was not completed. You can try again below.</div>
    <?php elseif ($paymentStatus === 'error'): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);">Couldn't start the payment — please try again or use another method.</div>
    <?php endif; ?>

    <p><strong>Status:</strong>
        <?php if ($invoice['status'] === 'paid'): ?>
            <span class="cv-badge cv-badge--success">Paid</span>
        <?php else: ?>
            <span class="cv-badge cv-badge--danger">Unpaid</span>
        <?php endif; ?>
    </p>

    <table class="cv-table">
        <thead><tr><th>Description</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr><td><?= e($item['description']) ?></td><td><?= $money((float) $item['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td style="font-weight:700;">Total</td><td style="font-weight:700;"><?= $money((float) $invoice['total']) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($invoice['status'] === 'unpaid'): ?>
        <h2 class="cv-card__title">Pay This Invoice</h2>

        <?php if ($creditBalance > 0): ?>
            <div class="cv-card" style="margin-bottom:var(--cv-space-3);">
                <p>Account credit available: <strong><?= $money($creditBalance) ?></strong></p>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Account credit is always tracked in the base currency; shown here converted at this invoice's rate.</p>
                <form method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/apply-credit"><?= csrf_field() ?>
                    <button class="cv-btn" type="submit">Apply Credit to This Invoice</button>
                </form>
            </div>
        <?php endif; ?>

        <?php foreach ($gateways as $gateway): ?>
            <?php if ($gateway['slug'] === 'manual'): ?>
                <div class="cv-card">
                    <strong><?= e($gateway['name']) ?></strong>
                    <?php $config = json_decode((string) ($gateway['config'] ?? '{}'), true) ?: []; ?>
                    <p style="color:var(--cv-text-secondary);white-space:pre-line;"><?= e($config['bank_details'] ?? 'Contact us for bank transfer details, then we will confirm your payment.') ?></p>
                    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Once we receive your transfer, an admin will mark this invoice as paid.</p>
                </div>
            <?php elseif (in_array($gateway['slug'], ['paystack', 'flutterwave', 'payhub', 'plisio'], true)): ?>
                <div class="cv-card">
                    <form method="post" action="/client/invoices/<?= (int) $invoice['id'] ?>/pay/<?= e($gateway['slug']) ?>"><?= csrf_field() ?>
                        <button class="cv-btn" type="submit">Pay with <?= e($gateway['name']) ?></button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($transactions !== []): ?>
        <h2 class="cv-card__title">Payments</h2>
        <table class="cv-table">
            <thead><tr><th>Date</th><th>Gateway</th><th>Amount</th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $tx): ?>
                <tr><td><?= e($tx['created_at']) ?></td><td><?= e($tx['gateway_slug']) ?></td><td><?= $money((float) $tx['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
