<?php
/**
 * The username changer's Pay step: wallet (one click, when it covers the fee)
 * or any configured gateway. Rendered inside the modal on page load and fetched
 * as a fragment right after a PIN-confirmed submit (ClientUsernameController::payPanel).
 *
 * Gateways use the platform's own invoice payment routes; the callback brings the
 * client back to the service page (UsernameChangePayment::withReturn()).
 *
 * @var array<string, mixed> $pay UsernameChangePayment::summary()
 */
$sid = (int) $pay['service_id'];
$rid = (int) $pay['request_id'];
$wallet = $pay['wallet'];
?>
<section class="ucn-pay" data-ucn-pay data-invoice="<?= (int) $pay['invoice_id'] ?>">
    <div class="ucn-pay__due">
        <span class="ucn-pay__label">Amount due</span>
        <strong class="ucn-pay__amount"><?= e((string) $pay['due_label']) ?></strong>
        <span class="ucn-muted">
            Invoice #<?= (int) $pay['invoice_id'] ?>
            <?php if (!empty($pay['paid_label'])): ?> · <?= e((string) $pay['paid_label']) ?> already paid<?php endif; ?>
        </span>
    </div>

    <?php if (!empty($pay['consolidated'])): ?>
        <p class="ucn-pay__note">
            This fee is part of a combined invoice.
            <a class="ucn-btn ucn-btn--primary" href="/client/invoices/<?= (int) $pay['pay_invoice_id'] ?>">Pay invoice #<?= (int) $pay['pay_invoice_id'] ?></a>
        </p>
    <?php else: ?>
        <div class="ucn-pay__options">
            <div class="ucn-payopt ucn-payopt--wallet<?= $wallet['covers'] ? '' : ' is-short' ?>">
                <div class="ucn-payopt__text">
                    <strong>Wallet balance</strong>
                    <span class="ucn-muted"><?= e((string) $wallet['balance_label']) ?> available</span>
                </div>
                <?php if ($wallet['covers']): ?>
                    <form method="post" action="/client/services/<?= $sid ?>/username/<?= $rid ?>/pay/wallet" data-ucn-wallet>
                        <?= csrf_field() ?>
                        <button class="ucn-btn ucn-btn--primary" type="submit" data-ucn-wallet-btn>Pay <?= e((string) $pay['due_label']) ?> from wallet</button>
                    </form>
                <?php else: ?>
                    <div class="ucn-payopt__short">
                        <span class="ucn-muted">You need <?= e((string) ($wallet['short_label'] ?? $pay['due_label'])) ?> more.</span>
                        <a class="ucn-btn" href="<?= e((string) $wallet['add_funds_url']) ?>">Add funds</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php foreach ($pay['gateways'] as $g): ?>
                <?php if ($g['manual']): ?>
                    <details class="ucn-payopt ucn-payopt--manual">
                        <summary><strong><?= e($g['name']) ?></strong> <span class="ucn-muted">— confirmed by our team</span></summary>
                        <p class="ucn-pay__bank"><?= nl2br(e($g['details'])) ?></p>
                        <p class="ucn-muted">Use invoice #<?= (int) $pay['invoice_id'] ?> as your reference. The change runs once the payment is confirmed.</p>
                    </details>
                <?php elseif ($g['inline']): ?>
                    <div class="ucn-payopt">
                        <div class="ucn-payopt__text"><strong><?= e($g['name']) ?></strong><span class="ucn-muted">Card, bank or transfer</span></div>
                        <button class="ucn-btn ucn-btn--primary" type="button"
                                data-payhub-pay
                                data-invoice-id="<?= (int) $pay['invoice_id'] ?>"
                                data-gateway-slug="<?= e($g['slug']) ?>"
                                data-gateway-name="<?= e($g['name']) ?>"
                                data-token="<?= e(csrf_token()) ?>">Pay with <?= e($g['name']) ?></button>
                    </div>
                <?php else: ?>
                    <form class="ucn-payopt" method="post" action="/client/invoices/<?= (int) $pay['invoice_id'] ?>/pay/<?= e($g['slug']) ?>" data-ucn-gateway>
                        <?= csrf_field() ?>
                        <div class="ucn-payopt__text"><strong><?= e($g['name']) ?></strong><span class="ucn-muted">Secure online payment</span></div>
                        <button class="ucn-btn ucn-btn--primary" type="submit">Pay with <?= e($g['name']) ?></button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($pay['gateways'] === [] && !$wallet['covers']): ?>
                <p class="ucn-muted">No online payment method is available right now. <a href="<?= e((string) $pay['invoice_url']) ?>">Open the invoice</a> for other options.</p>
            <?php endif; ?>
        </div>
        <p class="ucn-muted ucn-pay__foot">Your username changes automatically as soon as the payment clears. <a href="<?= e((string) $pay['invoice_url']) ?>">View invoice</a></p>
    <?php endif; ?>
</section>
