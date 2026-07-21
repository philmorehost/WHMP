<?php
/** @var array<int, array<string, mixed>> $methods */
/** @var string|null $status */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Payment Methods</h1>
    <p><a href="/client/invoices">&larr; Back to billing</a></p>
    <p style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm); margin-bottom:0;">
        Saved cards are used to pay your renewals automatically when an invoice is due, so your services never lapse. A card is saved securely by your payment provider the first time you pay an invoice with it — we only ever store a reference and the last 4 digits, never the full card number.
    </p>
</div>

<?php if ($status !== null): ?>
    <div class="cv-badge <?= $status === 'removed' || $status === 'default-set' ? 'cv-badge--success' : 'cv-badge--neutral' ?>" style="display:block; padding:var(--cv-space-3); margin-bottom:var(--cv-space-4);">
        <?php
        echo match ($status) {
            'default-set' => 'Default payment method updated — this card will be used for automatic renewals.',
            'removed' => 'Payment method removed. It will no longer be charged automatically.',
            'notfound' => 'That payment method could not be found.',
            default => 'Done.',
        };
        ?>
    </div>
<?php endif; ?>

<div class="cv-card">
    <?php if ($methods === []): ?>
        <p style="color:var(--cv-text-secondary);">
            You have no saved payment methods yet. The next time you pay an invoice with a card, we'll offer to keep it on file so future renewals are paid automatically.
        </p>
    <?php else: ?>
        <table class="cv-table">
            <thead><tr><th>Card</th><th>Expires</th><th>Provider</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $method): ?>
                <tr>
                    <td>
                        <?= e(ucfirst((string) ($method['card_brand'] ?: 'Card'))) ?>
                        &bull;&bull;&bull;&bull; <?= e($method['card_last4'] ?: '••••') ?>
                    </td>
                    <td>
                        <?php if (!empty($method['card_exp_month']) && !empty($method['card_exp_year'])): ?>
                            <?= e(str_pad((string) $method['card_exp_month'], 2, '0', STR_PAD_LEFT)) ?>/<?= e($method['card_exp_year']) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?= e(ucfirst((string) $method['gateway_slug'])) ?></td>
                    <td>
                        <?php if ((int) $method['is_default'] === 1): ?>
                            <span class="cv-badge cv-badge--success">Default</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--neutral">Saved</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if ((int) $method['is_default'] !== 1): ?>
                            <form method="post" action="/client/payment-methods/<?= (int) $method['id'] ?>/default" style="display:inline;"><?= csrf_field() ?>
                                <button type="submit" class="cv-btn cv-btn--secondary">Make default</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/client/payment-methods/<?= (int) $method['id'] ?>/delete" style="display:inline;" data-confirm="Remove this card? Renewals will no longer be paid automatically with it."><?= csrf_field() ?>
                            <button type="submit" class="cv-btn cv-btn--danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
