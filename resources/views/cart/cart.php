<?php
/** @var array{lines: array<int, array<string, mixed>>, subtotal: float, setupFees: float, total: float} $priced */
/** @var bool $loggedIn */
/** @var array<int, array<string, mixed>> $upsells */
/** @var string|null $error */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
/** @var callable(float): string $money supplied by CheckoutController::page() */
?>
<style>
/* ====== Checkout Page Styles ====== */
.checkout-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 48px 40px;
    margin-bottom: 40px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.checkout-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.12) 0%, transparent 70%);
    pointer-events: none;
}
.checkout-hero__content {
    position: relative;
    z-index: 1;
    max-width: 1200px;
    margin: 0 auto;
}
.checkout-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 12px 0;
    line-height: 1.2;
}
.checkout-hero__subtitle {
    color: rgba(255,255,255,.75);
    margin: 0;
    font-size: 1rem;
}
.checkout-hero__link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #f59e0b;
    text-decoration: none;
    font-weight: 600;
    margin-top: 16px;
    transition: all 0.2s;
}
.checkout-hero__link:hover {
    gap: 10px;
    color: #fbbf24;
}

/* Main Checkout Layout */
.checkout-wrapper {
    max-width: 1400px;
    margin: 0 auto;
}

/* Error Alert */
.alert {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(239, 68, 68, 0.04));
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--cv-color-danger, #ef4444);
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 32px;
    font-size: .95rem;
}

/* Grid Layout */
.checkout-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 32px;
    align-items: start;
}

/* Items Card */
.items-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.items-card__header {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    display: flex;
    align-items: center;
    gap: 12px;
}
.items-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
}
.items-card__body {
    padding: 0;
}

/* Cart Item */
.cart-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.cart-item:hover {
    background: var(--cv-bg-surface-sunken);
}
.cart-item:last-child {
    border-bottom: none;
}
.cart-item__details {
    flex: 1;
}
.cart-item__name {
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
}
.cart-item__meta {
    color: var(--cv-text-secondary);
    font-size: .9rem;
    margin: 0;
}
.cart-item__price {
    text-align: right;
    flex-shrink: 0;
    padding-left: 20px;
}
.cart-item__amount {
    font-weight: 800;
    font-size: 1.2rem;
    color: var(--cv-color-brand-500);
    margin: 0 0 4px 0;
}
.cart-item__cycle {
    font-size: .85rem;
    color: var(--cv-text-secondary);
    margin: 0 0 12px 0;
}
.cart-item__remove {
    background: var(--cv-color-danger-50, rgba(239,68,68,0.1));
    color: var(--cv-color-danger, #ef4444);
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.cart-item__remove:hover {
    background: rgba(239,68,68,0.2);
}

/* Domain Info */
.domain-info {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04));
    border-left: 4px solid var(--cv-color-success-600);
    padding: 12px 16px;
    border-radius: 0 8px 8px 0;
    margin-top: 12px;
    font-size: .9rem;
    color: var(--cv-text-primary);
}

/* Upsells Section */
.upsells-section {
    margin-top: 32px;
}
.upsells-section__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 20px 0;
}
.upsells-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}
.upsell-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s;
}
.upsell-card:hover {
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 8px 16px rgba(37,99,235,.1);
    transform: translateY(-2px);
}
.upsell-card__name {
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0 0 8px 0;
    font-size: .95rem;
}
.upsell-card__price {
    font-weight: 700;
    color: var(--cv-color-brand-500);
    font-size: 1.1rem;
    margin: 8px 0;
}
.upsell-card__desc {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    margin: 0 0 16px 0;
    line-height: 1.4;
}
.upsell-card__btn {
    background: linear-gradient(135deg, rgba(37,99,235,.1), rgba(37,99,235,.05));
    color: var(--cv-color-brand-500);
    border: 1px solid var(--cv-color-brand-500);
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 600;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.upsell-card__btn:hover {
    background: linear-gradient(135deg, var(--cv-color-brand-500), #2563eb);
    color: white;
}

/* Summary Card - Sticky */
.summary-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    padding: 28px;
    position: sticky;
    top: 20px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}
.summary-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 24px 0;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--cv-border-default);
}

/* Summary Lines */
.summary-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    font-size: .95rem;
}
.summary-line__label {
    color: var(--cv-text-secondary);
}
.summary-line__value {
    font-weight: 600;
    color: var(--cv-text-primary);
}
.summary-line--discount {
    color: var(--cv-color-success-600);
}
.summary-line--discount .summary-line__value {
    color: var(--cv-color-success-600);
}
.summary-line--total {
    padding-top: 16px;
    margin-top: 16px;
    border-top: 2px solid var(--cv-border-default);
    font-size: 1.15rem;
    font-weight: 800;
}
.summary-line--total .summary-line__value {
    color: var(--cv-color-brand-500);
    font-size: 1.4rem;
}

/* Promo Section */
.promo-section {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--cv-border-default);
}
.promo-applied {
    background: linear-gradient(135deg, rgba(16,185,129,0.08), rgba(16,185,129,0.04));
    border: 1px dashed var(--cv-color-success-600);
    padding: 12px 16px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .9rem;
}
.promo-applied__code {
    font-weight: 700;
    color: var(--cv-color-success-600);
}
.promo-form {
    display: flex;
    gap: 8px;
}
.promo-form input {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    background: var(--cv-bg-surface-sunken);
    color: var(--cv-text-primary);
    font-size: .9rem;
}
.promo-form input::placeholder {
    color: var(--cv-text-secondary);
}
.promo-form button {
    padding: 10px 16px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}
.promo-form button:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-1px);
}

/* CTA Section */
.cta-section {
    margin-top: 28px;
}
.cta-button {
    width: 100%;
    padding: 16px 24px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 12px;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.05rem;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.cta-button:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(37,99,235,.3);
}
.cta-button:active {
    transform: translateY(0);
}

/* Notes Textarea */
.notes-field {
    margin-bottom: 16px;
}
.notes-field label {
    display: block;
    font-size: .85rem;
    font-weight: 600;
    color: var(--cv-text-primary);
    margin-bottom: 8px;
}
.notes-field textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    background: var(--cv-bg-surface-sunken);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
    resize: none;
}

/* Auth Prompt */
.auth-section {
    text-align: center;
    padding: 40px;
}
.auth-section__text {
    color: var(--cv-text-secondary);
    font-size: .9rem;
    margin: 0 0 16px 0;
}
.auth-buttons {
    display: flex;
    gap: 12px;
}
.auth-btn {
    flex: 1;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    font-size: .9rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}
.auth-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.auth-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-1px);
}
.auth-btn--secondary {
    background: var(--cv-bg-surface-sunken);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.auth-btn--secondary:hover {
    background: var(--cv-border-default);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 40px;
}
.empty-state__icon {
    font-size: 3.5rem;
    margin-bottom: 20px;
}
.empty-state__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}
.empty-state__text {
    color: var(--cv-text-secondary);
    margin: 0 0 24px 0;
}
.empty-state__btn {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s;
}
.empty-state__btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
}

/* Mobile Responsive */
@media (max-width: 1024px) {
    .checkout-grid {
        grid-template-columns: 1fr;
    }
    .summary-card {
        position: static;
    }
}

@media (max-width: 768px) {
    .checkout-hero {
        padding: 32px 20px;
    }
    .checkout-hero__title {
        font-size: 1.5rem;
    }
    .checkout-grid {
        gap: 20px;
    }
    .items-card__body {
        max-height: 60vh;
        overflow-y: auto;
    }
}
</style>

<div class="checkout-wrapper">
    <!-- Hero Section -->
    <div class="checkout-hero">
        <div class="checkout-hero__content">
            <h1 class="checkout-hero__title">Review & Checkout</h1>
            <p class="checkout-hero__subtitle">Complete your order and get started today</p>
            <a href="/store" class="checkout-hero__link">
                <span>← Continue Shopping</span>
            </a>
        </div>
    </div>

    <!-- Error Alert -->
    <?php if (!empty($error)): ?>
        <div class="alert">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Empty Cart State -->
    <?php if ($priced['lines'] === []): ?>
        <div class="items-card">
            <div class="empty-state">
                <div class="empty-state__icon">🛒</div>
                <h2 class="empty-state__title"><?= e($t->get('cart.empty')) ?></h2>
                <p class="empty-state__text">Explore our premium products to get started.</p>
                <a href="/store" class="empty-state__btn">Browse Shop</a>
            </div>
        </div>
    <?php else: ?>
        <!-- Checkout Grid -->
        <div class="checkout-grid">
            <!-- Left: Order Items -->
            <div>
                <!-- Items Card -->
                <div class="items-card">
                    <div class="items-card__header">
                        <span>📦</span>
                        <h3 class="items-card__title">Your Order Items (<?= count($priced['lines']) ?>)</h3>
                    </div>
                    <div class="items-card__body">
                        <?php foreach ($priced['lines'] as $line): ?>
                            <div class="cart-item">
                                <div class="cart-item__details">
                                    <h4 class="cart-item__name"><?= e($line['product_name']) ?></h4>
                                    <p class="cart-item__meta">
                                        <?= e($line['cycle_label']) ?> × <?= (int) $line['quantity'] ?>
                                        <?php if (!empty($line['options'])): ?>
                                            <br>Options: <?= e(implode(', ', array_column($line['options'], 'name'))) ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($line['domain_options'])): ?>
                                        <div class="domain-info">
                                            🌐 <strong><?= e($line['domain_options']['name']) ?></strong>
                                            (<?= e(ucfirst($line['domain_options']['option'])) ?>)
                                            <?php if (!empty($line['domain_price']) && $line['domain_price'] > 0): ?>
                                                <br>+ <?= $money((float) $line['domain_price']) ?> / Year
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$line['in_stock']): ?>
                                        <div style="margin-top: 8px;">
                                            <span style="background: rgba(239,68,68,0.1); color: #ef4444; padding: 4px 8px; border-radius: 4px; font-size: .8rem; font-weight: 600;">Out of Stock</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="cart-item__price">
                                    <p class="cart-item__amount"><?= $money((float) $line['line_total']) ?></p>
                                    <form method="post" action="/cart/remove/<?= (int) $line['index'] ?>"><?= csrf_field() ?>
                                        <button type="submit" class="cart-item__remove">Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Upsells Section -->
                <?php if ($upsells !== []): ?>
                    <div class="upsells-section">
                        <h3 class="upsells-section__title">✨ Recommended Add-ons</h3>
                        <div class="upsells-grid">
                            <?php foreach ($upsells as $upsell): ?>
                                <div class="upsell-card">
                                    <div>
                                        <h4 class="upsell-card__name"><?= e($upsell['name']) ?></h4>
                                        <p class="upsell-card__price"><?= $money((float) $upsell['upsell_price']) ?></p>
                                        <?php if (!empty($upsell['upsell_pitch'])): ?>
                                            <p class="upsell-card__desc"><?= e($upsell['upsell_pitch']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" action="/cart/add"><?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= (int) $upsell['id'] ?>">
                                        <input type="hidden" name="billing_cycle" value="<?= e($upsell['upsell_cycle']) ?>">
                                        <button type="submit" class="upsell-card__btn">Add to Cart</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Order Summary (Sticky) -->
            <aside>
                <div class="summary-card">
                    <h3 class="summary-card__title">💰 Order Summary</h3>

                    <div class="summary-line">
                        <span class="summary-line__label">Subtotal</span>
                        <span class="summary-line__value"><?= $money($priced['subtotal']) ?></span>
                    </div>

                    <?php if ($priced['setupFees'] > 0): ?>
                        <div class="summary-line">
                            <span class="summary-line__label">Setup Fees</span>
                            <span class="summary-line__value"><?= $money($priced['setupFees']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($priced['domainTotal']) && $priced['domainTotal'] > 0): ?>
                        <div class="summary-line">
                            <span class="summary-line__label">Domain Registration</span>
                            <span class="summary-line__value"><?= $money($priced['domainTotal']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($priced['discount'] > 0): ?>
                        <div class="summary-line summary-line--discount">
                            <span class="summary-line__label">Discount</span>
                            <span class="summary-line__value">-<?= $money($priced['discount']) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="summary-line summary-line--total">
                        <span class="summary-line__label">Total Due</span>
                        <span class="summary-line__value"><?= $money($priced['total']) ?></span>
                    </div>

                    <!-- Promo Code -->
                    <div class="promo-section">
                        <?php if (!empty($priced['promoCode']) && $priced['discount'] > 0): ?>
                            <div class="promo-applied">
                                <span class="promo-applied__code">✓ Code: <?= e($priced['promoCode']) ?></span>
                                <form method="post" action="/cart/remove-promo" style="margin: 0;"><?= csrf_field() ?>
                                    <button type="submit" style="background: none; border: none; color: var(--cv-color-success-600); cursor: pointer; font-weight: 600; font-size: .8rem;">Remove</button>
                                </form>
                            </div>
                        <?php elseif (!empty($priced['promoError'])): ?>
                            <div style="background: rgba(239,68,68,0.08); border: 1px dashed #ef4444; padding: 10px 12px; border-radius: 6px; font-size: .85rem; color: #ef4444; display: flex; justify-content: space-between; align-items: center;">
                                <span><?= e($priced['promoError']) ?></span>
                                <form method="post" action="/cart/remove-promo"><?= csrf_field() ?>
                                    <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer; font-weight: 600;">✕</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="post" action="/cart/apply-promo" class="promo-form"><?= csrf_field() ?>
                                <input type="text" name="promo_code" placeholder="Promo code">
                                <button type="submit">Apply</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- CTA -->
                    <div class="cta-section">
                        <?php if ($loggedIn): ?>
                            <form method="post" action="/cart/checkout"><?= csrf_field() ?>
                                <?php
                                $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
                                if ($settingsRepo->get('checkout.allow_notes', '1') === '1'):
                                ?>
                                    <div class="notes-field">
                                        <label>Order Notes (optional)</label>
                                        <textarea name="notes" rows="2" placeholder="Any special requests..."></textarea>
                                    </div>
                                <?php endif; ?>
                                <button type="submit" class="cta-button">Place Order →</button>
                            </form>
                        <?php else: ?>
                            <div class="auth-section">
                                <p class="auth-section__text"><?= e($t->get('cart.login_prompt')) ?></p>
                                <div class="auth-buttons">
                                    <a href="/client/login" class="auth-btn auth-btn--primary">Sign In</a>
                                    <a href="/client/register" class="auth-btn auth-btn--secondary">Create Account</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>
