<?php
/** @var array<string, mixed> $product */
/** @var array<string, array<string, mixed>> $pricing */
/** @var array<string, string> $cycles */
/** @var array<int, array<string, mixed>> $optionGroups */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
/** @var array<int, string> $defaultNameservers */
/** @var array<int, string> $domainTlds */
$money = static fn (float $amount): string => ($currency['symbol'] ?? '$') . number_format(($amount > 1000 && (float) ($currency['exchange_rate'] ?? 1) > 50 && ($amount * (float) ($currency['exchange_rate'] ?? 1) > 5000000)) ? $amount : ($amount * (float) ($currency['exchange_rate'] ?? 1)), 2);
$domainTlds = $domainTlds !== [] ? $domainTlds : ['.com', '.net', '.org'];
?>
<style>
/* ====== Product Page Styles ====== */
.product-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 0;
}

.product-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 48px 40px;
    border-radius: 16px;
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
}
.product-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.1) 0%, transparent 70%);
    pointer-events: none;
}

.product-hero__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    position: relative;
    z-index: 1;
}

.product-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}

.product-hero__back {
    color: #f59e0b;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    white-space: nowrap;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.product-hero__back:hover {
    gap: 10px;
    color: #fbbf24;
}

/* Form Sections */
.form-section {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    transition: all 0.3s ease;
}
.form-section:hover {
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 4px 12px rgba(37,99,235,.08);
}

.form-section__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 24px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.form-section__title-icon {
    font-size: 1.4rem;
}

/* Domain Section */
.domain-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}
.domain-option {
    display: flex;
    align-items: center;
    padding: 14px 16px;
    border: 2px solid var(--cv-border-default);
    border-radius: 10px;
    background: var(--cv-bg-surface);
    cursor: pointer;
    transition: all 0.2s;
}
.domain-option:hover {
    border-color: var(--cv-color-brand-500);
    background: var(--cv-bg-surface-sunken);
}
.domain-option input[type="radio"]:checked + label {
    color: var(--cv-color-brand-500);
}
.domain-option input[type="radio"] {
    margin-right: 12px;
    cursor: pointer;
    accent-color: var(--cv-color-brand-500);
}
.domain-option label {
    cursor: pointer;
    flex: 1;
    font-weight: 500;
}

/* Billing Cycle Selector */
.cycle-options {
    display: grid;
    gap: 12px;
    margin-bottom: 24px;
}
.cycle-option {
    display: flex;
    align-items: center;
    padding: 16px;
    border: 2px solid var(--cv-border-default);
    border-radius: 12px;
    background: var(--cv-bg-surface);
    cursor: pointer;
    transition: all 0.2s;
}
.cycle-option:hover {
    border-color: var(--cv-color-brand-500);
    background: var(--cv-bg-surface-sunken);
}
.cycle-option input[type="radio"]:checked {
    accent-color: var(--cv-color-brand-500);
}
.cycle-option input[type="radio"] {
    margin-right: 14px;
    cursor: pointer;
    width: 18px;
    height: 18px;
}
.cycle-option__label {
    flex: 1;
}
.cycle-option__name {
    font-weight: 700;
    color: var(--cv-text-primary);
    font-size: 1rem;
    margin-bottom: 4px;
}
.cycle-option__price {
    color: var(--cv-text-secondary);
    font-size: .9rem;
}

/* Form Field */
.form-field {
    margin-bottom: 20px;
}
.form-field label {
    display: block;
    font-weight: 600;
    font-size: .95rem;
    color: var(--cv-text-primary);
    margin-bottom: 8px;
}
.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--cv-border-default);
    border-radius: 10px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: 1rem;
    font-family: inherit;
    transition: all 0.2s;
}
.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

/* Accordion */
[data-details-accordion] {
    margin-top: 16px;
}
[data-accordion-trigger] {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    background: none;
    border: none;
    padding: 12px 0;
    color: var(--cv-color-brand-500);
    font-weight: 600;
    cursor: pointer;
    font-size: .95rem;
    text-align: left;
}
[data-accordion-trigger]:hover {
    color: var(--cv-color-brand-600);
}
[data-accordion-icon] {
    width: 18px;
    height: 18px;
    transition: transform 0.2s;
    flex-shrink: 0;
}
[data-accordion-content] {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.25s ease-out;
}

/* CTA Button */
.cta-button {
    width: 100%;
    padding: 16px 24px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Hanken Grotesk', sans-serif;
}
.cta-button:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(37,99,235,.3);
}
.cta-button:active {
    transform: translateY(0);
}

/* Pricing Display */
.price-highlight {
    background: linear-gradient(135deg, rgba(245,158,11,.1), rgba(249,115,22,.05));
    border: 1px solid rgba(245,158,11,.2);
    border-radius: 12px;
    padding: 16px;
    margin: 24px 0;
    text-align: center;
}
.price-highlight__label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 600;
    display: block;
    margin-bottom: 8px;
}
.price-highlight__amount {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #f59e0b;
    line-height: 1;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .product-page {
        padding: 0 16px;
    }
    .product-hero {
        padding: 32px 24px;
    }
    .product-hero__title {
        font-size: 1.5rem;
    }
    .form-section {
        padding: 24px;
    }
}
</style>

<div class="product-page">
    <!-- Hero Section -->
    <div class="product-hero">
        <div class="product-hero__header">
            <h1 class="product-hero__title"><?= e($product['name']) ?></h1>
            <a href="/store" class="product-hero__back">
                <span>←</span>
                <span>Back to Store</span>
            </a>
        </div>
    </div>

    <?php if ($pricing === []): ?>
        <div class="form-section" style="text-align: center; padding: 60px 40px;">
            <p style="color: var(--cv-text-secondary); margin: 0;">This product is currently not available.</p>
        </div>
    <?php else: ?>
        <form method="post" action="/cart/add" id="product-order-form"><?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

            <!-- Domain Configuration Section -->
            <?php if (!empty($product['require_domain'])): ?>
                <div class="form-section">
                    <h2 class="form-section__title">
                        <span class="form-section__title-icon">🌐</span>
                        Domain Configuration
                    </h2>

                    <div class="domain-options">
                        <label class="domain-option">
                            <input type="radio" name="domain_option" value="register" checked data-domain-option-toggle>
                            <label>Register a new domain name</label>
                        </label>
                        <label class="domain-option">
                            <input type="radio" name="domain_option" value="transfer" data-domain-option-toggle>
                            <label>Transfer your domain from another registrar</label>
                        </label>
                        <label class="domain-option">
                            <input type="radio" name="domain_option" value="existing" data-domain-option-toggle>
                            <label>I will use my existing domain and update nameservers</label>
                        </label>
                    </div>

                    <div id="domain-input-wrapper" style="display: flex; gap: 12px; align-items: flex-end; margin-bottom: 20px;" data-tld-options="<?= e(json_encode($domainTlds)) ?>">
                        <label class="form-field" style="margin: 0; flex-shrink: 0;">
                            <span>www.</span>
                        </label>
                        <div class="form-field" style="margin: 0; flex: 1;">
                            <input type="text" name="domain_name" placeholder="example" required data-domain-availability-input style="margin: 0;">
                        </div>
                        <select class="form-field" name="domain_tld" style="margin: 0; width: 110px;" data-domain-availability-tld>
                            <?php foreach ($domainTlds as $tld): ?>
                                <option value="<?= e($tld) ?>"><?= e($tld) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div data-domain-availability-result style="font-size: .85rem; margin-bottom: 16px;"></div>

                    <div id="nameserver-wrapper" style="display: none; transition: all 0.2s ease-in-out;">
                        <label style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <span style="font-weight: 600;">Nameservers</span>
                            <span style="font-size: .8rem; color: var(--cv-text-secondary);">Using default nameservers</span>
                        </label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input class="form-field" style="margin: 0;" type="text" name="ns<?= $i ?>" value="<?= e($defaultNameservers[$i - 1] ?? '') ?>" placeholder="ns<?= $i ?>.yournameservers.com<?= $i > 2 ? ' (optional)' : '' ?>">
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Product Details Section -->
            <div class="form-section">
                <h2 class="form-section__title">
                    <span class="form-section__title-icon">📋</span>
                    Product Details
                </h2>

                <?php if (!empty($product['description'])): ?>
                    <div data-details-accordion>
                        <button type="button" data-accordion-trigger>
                            <span>View Product Details</span>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" data-accordion-icon><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div data-accordion-content style="color: var(--cv-text-secondary); font-size: .95rem; line-height: 1.6; white-space: pre-wrap; padding-top: 12px;">
                            <?= e((string) $product['description']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Billing Cycle Section -->
            <div class="form-section">
                <h2 class="form-section__title">
                    <span class="form-section__title-icon">💳</span>
                    Billing Cycle
                </h2>

                <div class="cycle-options">
                    <?php $first = true; foreach ($pricing as $cycleKey => $row): ?>
                        <label class="cycle-option">
                            <input type="radio" name="billing_cycle" value="<?= e($cycleKey) ?>" required <?= $first ? 'checked' : '' ?>>
                            <div class="cycle-option__label">
                                <div class="cycle-option__name"><?= e($cycles[$cycleKey] ?? $cycleKey) ?></div>
                                <div class="cycle-option__price">
                                    <?php if ((float) $row['setup_fee'] > 0): ?>
                                        <?= $money((float) $row['setup_fee']) ?> setup +
                                    <?php endif; ?>
                                    <?= $money((float) $row['price']) ?> per cycle
                                </div>
                            </div>
                        </label>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>

            <!-- Additional Information Section -->
            <?php if (!empty($optionGroups) || in_array($product['type'] ?? '', ['vps', 'dedicated'], true) || !empty($customFields)): ?>
                <div class="form-section">
                    <h2 class="form-section__title">
                        <span class="form-section__title-icon">⚙️</span>
                        Configuration
                    </h2>

                    <?php if (in_array($product['type'] ?? '', ['vps', 'dedicated'], true)): ?>
                        <div class="form-field">
                            <label>Hostname <span style="color: var(--cv-color-danger, #ef4444);">*</span></label>
                            <input type="text" name="hostname" placeholder="server1.yourdomain.com" required>
                        </div>
                        <div class="form-field">
                            <label>Root Password <span style="color: var(--cv-color-danger, #ef4444);">*</span></label>
                            <input type="password" name="root_password" placeholder="Choose a secure root password" required>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($optionGroups as $og): ?>
                        <div class="form-field">
                            <label><?= e($og['name']) ?></label>
                            <select name="option[<?= (int) $og['id'] ?>]">
                                <option value="">None</option>
                                <?php foreach ($og['options'] as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= e($option['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($customFields)): ?>
                        <?php foreach ($customFields as $field): ?>
                            <div class="form-field">
                                <label><?= e($field['name']) ?> <?= $field['required'] ? '<span style="color: var(--cv-color-danger, #ef4444);">*</span>' : '' ?></label>
                                <?php if ($field['type'] === 'textarea'): ?>
                                    <textarea name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>></textarea>
                                <?php elseif ($field['type'] === 'dropdown'): ?>
                                    <select name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>>
                                        <?php foreach (explode(',', (string) $field['options']) as $opt): ?>
                                            <option value="<?= e(trim($opt)) ?>"><?= e(trim($opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="<?= $field['type'] === 'password' ? 'password' : 'text' ?>" name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="form-field">
                        <label>Quantity</label>
                        <input type="number" name="quantity" value="1" min="1" style="max-width: 100px;">
                    </div>
                </div>
            <?php endif; ?>

            <!-- Submit Button -->
            <button class="cta-button" type="submit">Add to Cart 🛒</button>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const trigger = document.querySelector('[data-accordion-trigger]');
    const content = document.querySelector('[data-accordion-content]');
    const icon = document.querySelector('[data-accordion-icon]');

    if (trigger && content) {
        function openAccordion() {
            content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
        }

        function closeAccordion() {
            content.style.maxHeight = '0';
            if (icon) icon.style.transform = 'rotate(0deg)';
        }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            if (content.style.maxHeight && content.style.maxHeight !== '0px') {
                closeAccordion();
            } else {
                openAccordion();
            }
        });
    }
});
</script>
