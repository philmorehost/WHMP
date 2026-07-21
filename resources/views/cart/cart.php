<?php
/** @var array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, total: float} $priced */
/** @var bool $loggedIn */
/** @var array<int, array<string, mixed>> $upsells */
/** @var string|null $error */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * (float) $currency['exchange_rate'], 2);
?>
<div style="max-width: 1400px; margin: 0 auto; padding: var(--cv-space-4);">
    <h1 style="font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-3xl); font-weight: 800; margin-bottom: var(--cv-space-4); color: var(--cv-text-primary);">Review & Checkout</h1>
    <p style="margin-bottom: var(--cv-space-6);"><a href="/store" style="color: var(--cv-color-brand-500); text-decoration: none; font-weight: 600;">&larr; <?= e($t->get('cart.continue_shopping')) ?></a></p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="background: rgba(239, 68, 68, 0.08); border: 1px solid var(--cv-color-danger, #ef4444); color: var(--cv-color-danger, #ef4444); padding: var(--cv-space-3); border-radius: var(--cv-radius-md); margin-bottom: var(--cv-space-4); font-size: var(--cv-text-sm);">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <?php if ($priced['lines'] === []): ?>
        <div class="cv-card" style="text-align: center; padding: var(--cv-space-8); border: 1px solid var(--cv-border-default);">
            <div style="font-size: 3rem; margin-bottom: var(--cv-space-4);">🛒</div>
            <h3 style="font-family: 'Hanken Grotesk', sans-serif; margin-bottom: var(--cv-space-2); color: var(--cv-text-primary);"><?= e($t->get('cart.empty')) ?></h3>
            <p style="color: var(--cv-text-secondary); margin-bottom: var(--cv-space-5);">Explore our premium products to get started.</p>
            <a href="/store" class="cv-btn">Browse Shop</a>
        </div>
    <?php else: ?>
        <div style="display: flex; gap: var(--cv-space-6); align-items: flex-start; flex-wrap: wrap;">
            <!-- Left Side: Cart Items -->
            <div style="flex: 2; min-width: 320px;">
                <div class="cv-card" style="padding: 0; overflow: hidden; border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface);">
                    <div style="padding: var(--cv-space-4); border-bottom: 1px solid var(--cv-border-default); background: var(--cv-bg-surface-sunken);">
                        <h3 style="margin: 0; font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-md);">Your Order Items</h3>
                    </div>
                    
                    <div style="padding: var(--cv-space-4);">
                        <?php foreach ($priced['lines'] as $line): ?>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; padding: var(--cv-space-4) 0; border-bottom: 1px solid var(--cv-border-default);">
                                <div style="flex: 1; min-width: 0; padding-right: var(--cv-space-4);">
                                    <div style="font-weight: 700; font-size: var(--cv-text-md); color: var(--cv-text-primary);"><?= e($line['product_name']) ?></div>
                                    
                                    <?php if ($line['options'] !== []): ?>
                                        <div style="color: var(--cv-text-secondary); font-size: var(--cv-text-xs); margin-top: var(--cv-space-1);">
                                            <strong>Options:</strong> <?= e(implode(', ', array_column($line['options'], 'name'))) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($line['domain_options'])): ?>
                                        <div style="background: rgba(47, 111, 237, 0.05); border-left: 3px solid var(--cv-color-brand-500); padding: var(--cv-space-2) var(--cv-space-3); border-radius: 0 var(--cv-radius-sm) var(--cv-radius-sm) 0; margin-top: var(--cv-space-2); font-size: var(--cv-text-xs); color: var(--cv-text-primary);">
                                            🌐 <strong>Domain:</strong> <?= e($line['domain_options']['name']) ?> (<?= e(ucfirst($line['domain_options']['option'])) ?>)
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!$line['in_stock']): ?>
                                        <div style="margin-top: var(--cv-space-2);">
                                            <span class="cv-badge cv-badge--danger"><?= e($t->get('cart.out_of_stock')) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="text-align: right; flex-shrink: 0;">
                                    <div style="font-weight: 800; font-size: var(--cv-text-md); color: var(--cv-color-brand-500);"><?= $money((float) $line['line_total']) ?></div>
                                    <div style="font-size: var(--cv-text-xs); color: var(--cv-text-secondary); margin-top: 2px;">
                                        <?= e($line['cycle_label']) ?> x<?= (int) $line['quantity'] ?>
                                    </div>
                                    
                                    <form method="post" action="/cart/remove/<?= (int) $line['index'] ?>" style="margin-top: var(--cv-space-3);"><?= csrf_field() ?>
                                        <button class="cv-btn cv-btn--danger" type="submit" style="padding: var(--cv-space-1) var(--cv-space-2); font-size: var(--cv-text-xs);">Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Add-on/Upsells Slider -->
                <?php if ($upsells !== []): ?>
                    <div class="cv-card" style="margin-top: var(--cv-space-6); border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface);">
                        <h2 class="cv-card__title" style="font-size: var(--cv-text-md); margin-bottom: var(--cv-space-4);"><?= e($t->get('cart.upsell_title')) ?></h2>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--cv-space-4);">
                            <?php foreach ($upsells as $upsell): ?>
                                <div style="border: 1px solid var(--cv-border-default); border-radius: var(--cv-radius-md); padding: var(--cv-space-4); display: flex; flex-direction: column; justify-content: space-between; background: var(--cv-bg-surface-sunken);">
                                    <div>
                                        <strong style="color: var(--cv-text-primary); font-size: var(--cv-text-sm);"><?= e($upsell['name']) ?></strong>
                                        <div style="font-weight: 700; color: var(--cv-color-brand-500); margin: var(--cv-space-1) 0; font-size: var(--cv-text-sm);">
                                            <?= $money((float) $upsell['upsell_price']) ?>
                                        </div>
                                        <?php if (!empty($upsell['upsell_pitch'])): ?>
                                            <div style="color: var(--cv-text-secondary); font-size: var(--cv-text-xs); margin-bottom: var(--cv-space-4);"><?= e($upsell['upsell_pitch']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" action="/cart/add"><?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= (int) $upsell['id'] ?>">
                                        <input type="hidden" name="billing_cycle" value="<?= e($upsell['upsell_cycle']) ?>">
                                        <button class="cv-btn cv-btn--secondary" type="submit" style="width: 100%; font-size: var(--cv-text-xs); padding: var(--cv-space-1);"><?= e($t->get('product.add_to_cart')) ?></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Side: Order Summary Card -->
            <div style="flex: 1; min-width: 300px; position: sticky; top: var(--cv-space-4);">
                <div class="cv-card" style="border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface);">
                    <h3 style="margin-top: 0; margin-bottom: var(--cv-space-4); font-family: 'Hanken Grotesk', sans-serif; font-size: var(--cv-text-lg); border-bottom: 1px solid var(--cv-border-default); padding-bottom: var(--cv-space-2);">Order Summary</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: var(--cv-space-3); font-size: var(--cv-text-sm);">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--cv-text-secondary);">Subtotal</span>
                            <span style="font-weight: 600; color: var(--cv-text-primary);"><?= $money($priced['subtotal']) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--cv-text-secondary);">Setup Fees</span>
                            <span style="font-weight: 600; color: var(--cv-text-primary);"><?= $money($priced['setupFees']) ?></span>
                        </div>
                        
                        <?php if ($priced['discount'] > 0): ?>
                            <div style="display: flex; justify-content: space-between; color: var(--cv-color-success-600);">
                                <span>Discount</span>
                                <span>-<?= $money($priced['discount']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; border-top: 1px solid var(--cv-border-default); padding-top: var(--cv-space-3); font-size: var(--cv-text-md); font-weight: 800;">
                            <span style="color: var(--cv-text-primary);">Total Due</span>
                            <span style="color: var(--cv-color-brand-500);"><?= $money($priced['total']) ?></span>
                        </div>
                    </div>

                    <!-- Promo Code Area -->
                    <div style="margin-top: var(--cv-space-5); padding-top: var(--cv-space-4); border-top: 1px solid var(--cv-border-default);">
                        <?php if (!empty($priced['promoCode']) && $priced['discount'] > 0): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(40, 167, 69, 0.08); padding: var(--cv-space-2); border-radius: var(--cv-radius-sm); border: 1px dashed var(--cv-color-success-600);">
                                <span style="font-size: var(--cv-text-xs); font-weight: 700; color: var(--cv-color-success-600);">Code: <?= e($priced['promoCode']) ?></span>
                                <form method="post" action="/cart/remove-promo" style="margin: 0;"><?= csrf_field() ?>
                                    <button class="cv-btn cv-btn--secondary" type="submit" style="padding: 2px 6px; font-size: var(--cv-text-2xs);">Remove</button>
                                </form>
                            </div>
                        <?php elseif (!empty($priced['promoError'])): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(239, 68, 68, 0.08); padding: var(--cv-space-2); border-radius: var(--cv-radius-sm); border: 1px dashed var(--cv-color-danger, #ef4444); color: var(--cv-color-danger, #ef4444); font-size: var(--cv-text-xs);">
                                <span><?= e($priced['promoError']) ?></span>
                                <form method="post" action="/cart/remove-promo" style="margin: 0;"><?= csrf_field() ?>
                                    <button class="cv-btn cv-btn--secondary" type="submit" style="padding: 2px 6px; font-size: var(--cv-text-2xs);">Dismiss</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="post" action="/cart/apply-promo" style="display: flex; gap: var(--cv-space-2);">
                                <?= csrf_field() ?>
                                <input class="cv-input" type="text" name="promo_code" placeholder="Promo code" style="flex: 1; font-size: var(--cv-text-xs);">
                                <button class="cv-btn cv-btn--secondary" type="submit" style="font-size: var(--cv-text-xs); padding: var(--cv-space-2);">Apply</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Action Button -->
                    <div style="margin-top: var(--cv-space-6);">
                        <?php if ($loggedIn): ?>
                            <form method="post" action="/cart/checkout" style="margin: 0;"><?= csrf_field() ?>
                                <button class="cv-btn" type="submit" style="width: 100%; padding: var(--cv-space-3); font-weight: 800; font-size: var(--cv-text-md);">Place Order &rarr;</button>
                            </form>
                        <?php else: ?>
                            <p style="color: var(--cv-text-secondary); font-size: var(--cv-text-xs); text-align: center; margin-bottom: var(--cv-space-3);"><?= e($t->get('cart.login_prompt')) ?></p>
                            <div style="display: flex; gap: var(--cv-space-2);">
                                <a class="cv-btn" href="/client/login" style="flex: 1; text-align: center; text-decoration: none; font-size: var(--cv-text-xs);"><?= e($t->get('nav.login')) ?></a>
                                <a class="cv-btn cv-btn--secondary" href="/client/register" style="flex: 1; text-align: center; text-decoration: none; font-size: var(--cv-text-xs);"><?= e($t->get('nav.register')) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
