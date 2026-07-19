<?php
/** @var array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, total: float} $priced */
/** @var bool $loggedIn */
/** @var array<int, array<string, mixed>> $upsells */
/** @var string|null $error */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * (float) $currency['exchange_rate'], 2);
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title"><?= e($t->get('cart.title')) ?></h1>
    <p><a href="/store">&larr; <?= e($t->get('cart.continue_shopping')) ?></a></p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <table class="cv-table">
        <thead><tr><th><?= e($t->get('cart.product')) ?></th><th><?= e($t->get('cart.cycle')) ?></th><th><?= e($t->get('cart.qty')) ?></th><th><?= e($t->get('cart.total')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($priced['lines'] as $line): ?>
            <tr>
                <td>
                    <?= e($line['product_name']) ?>
                    <?php if ($line['options'] !== []): ?>
                        <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
                            <?= e(implode(', ', array_column($line['options'], 'name'))) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$line['in_stock']): ?>
                        <span class="cv-badge cv-badge--danger"><?= e($t->get('cart.out_of_stock')) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($line['cycle_label']) ?></td>
                <td><?= (int) $line['quantity'] ?></td>
                <td><?= $money((float) $line['line_total']) ?></td>
                <td>
                    <form method="post" action="/cart/remove/<?= (int) $line['index'] ?>"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit"><?= e($t->get('cart.remove')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($priced['lines'] === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);"><?= e($t->get('cart.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($priced['lines'] !== []): ?>
        <div style="margin-top:var(--cv-space-4);border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);">
            <?php if (!empty($priced['promoCode']) && $priced['discount'] > 0): ?>
                <p style="color:var(--cv-color-success-600);">
                    Promo code <strong><?= e($priced['promoCode']) ?></strong> applied.
                    <form method="post" action="/cart/remove-promo" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--secondary" type="submit" style="padding:0.125rem 0.5rem;font-size:var(--cv-text-xs);">Remove</button>
                    </form>
                </p>
            <?php elseif (!empty($priced['promoError'])): ?>
                <div class="cv-field-error" style="margin-bottom:var(--cv-space-2);">
                    <?= e($priced['promoError']) ?>
                    <form method="post" action="/cart/remove-promo" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--secondary" type="submit" style="padding:0.125rem 0.5rem;font-size:var(--cv-text-xs);">Dismiss</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" action="/cart/apply-promo" style="display:flex;gap:var(--cv-space-2);">
                    <?= csrf_field() ?>
                    <input class="cv-input" type="text" name="promo_code" placeholder="Promo code" style="max-width:12rem;">
                    <button class="cv-btn cv-btn--secondary" type="submit">Apply</button>
                </form>
            <?php endif; ?>
        </div>

        <div style="text-align:right;margin-top:var(--cv-space-3);">
            <p><?= e($t->get('cart.subtotal')) ?>: <?= $money($priced['subtotal']) ?></p>
            <p><?= e($t->get('cart.setup_fees')) ?>: <?= $money($priced['setupFees']) ?></p>
            <?php if ($priced['discount'] > 0): ?>
                <p style="color:var(--cv-color-success-600);">Discount: -<?= $money($priced['discount']) ?></p>
            <?php endif; ?>
            <p style="font-weight:700;font-size:var(--cv-text-lg);"><?= e($t->get('cart.total')) ?>: <?= $money($priced['total']) ?></p>
        </div>

        <?php if ($loggedIn): ?>
            <form method="post" action="/cart/checkout" style="text-align:right;"><?= csrf_field() ?>
                <button class="cv-btn" type="submit"><?= e($t->get('cart.place_order')) ?></button>
            </form>
        <?php else: ?>
            <div style="text-align:right;">
                <p style="color:var(--cv-text-secondary);"><?= e($t->get('cart.login_prompt')) ?></p>
                <a class="cv-btn" href="/client/login"><?= e($t->get('nav.login')) ?></a>
                <a class="cv-btn cv-btn--secondary" href="/client/register"><?= e($t->get('nav.register')) ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($priced['lines'] !== [] && $upsells !== []): ?>
        <div style="margin-top:var(--cv-space-6);border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-4);">
            <h2 class="cv-card__title" style="font-size:var(--cv-text-md);"><?= e($t->get('cart.upsell_title')) ?></h2>
            <?php foreach ($upsells as $upsell): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-2);padding:var(--cv-space-2) 0;">
                    <div>
                        <strong><?= e($upsell['name']) ?></strong> &mdash; <?= $money((float) $upsell['upsell_price']) ?>
                        <?php if (!empty($upsell['upsell_pitch'])): ?>
                            <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e($upsell['upsell_pitch']) ?></div>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="/cart/add"><?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $upsell['id'] ?>">
                        <input type="hidden" name="billing_cycle" value="<?= e($upsell['upsell_cycle']) ?>">
                        <button class="cv-btn cv-btn--secondary" type="submit"><?= e($t->get('product.add_to_cart')) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
