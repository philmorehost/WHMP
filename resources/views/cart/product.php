<?php
/** @var array<string, mixed> $product */
/** @var array<string, array<string, mixed>> $pricing */
/** @var array<string, string> $cycles */
/** @var array<int, array<string, mixed>> $optionGroups */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
/** @var array<int, string> $defaultNameservers */
/** @var array<int, string> $domainTlds */
/** @var callable(float): string $money supplied by CheckoutController::page() */
/** @var string|null $error */
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

/* ---- Modern domain picker ---------------------------------------------
   The choice rows become selectable cards, and the name + TLD controls
   become one continuous field instead of three separate boxes with a raw
   OS dropdown sitting next to them — the native <select> chrome is what
   made this card look dated.
   NOTE: public/assets/js/app.js re-renders #domain-input-wrapper from
   scratch whenever the option changes. The markup it emits mirrors this
   exactly; change one and you must change the other. */
.domain-options { gap: 10px; }
.domain-option {
    position: relative;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border-width: 1.5px;
    border-radius: 14px;
}
.domain-option:has(input[type="radio"]:checked) {
    border-color: var(--cv-color-brand-500);
    background: color-mix(in srgb, var(--cv-color-brand-500) 7%, var(--cv-bg-surface));
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--cv-color-brand-500) 14%, transparent);
}
.domain-option input[type="radio"] {
    margin: 2px 0 0 0;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}
.domain-option__icon {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    font-size: 1rem;
    background: var(--cv-bg-surface-sunken);
}
.domain-option__text { flex: 1; min-width: 0; }
.domain-option__title { display: block; font-weight: 650; font-size: .95rem; }
.domain-option__hint {
    display: block;
    font-size: .8rem;
    color: var(--cv-text-secondary);
    font-weight: 400;
    margin-top: 2px;
}

/* One continuous control: prefix | name | TLD */
.domain-field {
    display: flex;
    align-items: stretch;
    border: 1.5px solid var(--cv-border-default);
    border-radius: 12px;
    background: var(--cv-bg-surface);
    overflow: hidden;
    transition: border-color .18s, box-shadow .18s;
}
.domain-field:focus-within {
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--cv-color-brand-500) 16%, transparent);
}
.domain-field__prefix {
    display: flex;
    align-items: center;
    padding: 0 4px 0 14px;
    font-size: .9rem;
    color: var(--cv-text-secondary);
    font-weight: 500;
    user-select: none;
}
.domain-field__input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    padding: 13px 8px;
    font-size: 1rem;
    font-weight: 500;
    color: var(--cv-text-primary);
    margin: 0;
}
.domain-field__divider {
    width: 1px;
    background: var(--cv-border-default);
    margin: 8px 0;
    flex-shrink: 0;
}
/* appearance:none strips the OS dropdown look; the chevron is drawn by us
   so it matches in both themes. */
.domain-field__tld {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    outline: 0;
    margin: 0;
    background: transparent;
    padding: 0 34px 0 14px;
    font-size: .95rem;
    font-weight: 650;
    color: var(--cv-color-brand-500);
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 14px;
}
.domain-field__tld:focus-visible { box-shadow: inset 0 0 0 2px var(--cv-color-brand-500); border-radius: 8px; }

.domain-result {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: .85rem;
    font-weight: 500;
    margin-top: 10px;
    min-height: 20px;
}
.domain-result::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
    opacity: .9;
}
.domain-result:empty { display: none; }

@media (max-width: 560px) {
    .domain-field { flex-wrap: wrap; }
    .domain-field__divider { display: none; }
    .domain-field__tld {
        width: 100%;
        border-top: 1px solid var(--cv-border-default);
        padding: 11px 34px 11px 14px;
    }
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
.product-description p {
    margin: 0 0 12px 0;
}
.product-description p:last-child {
    margin-bottom: 0;
}
.product-description ul,
.product-description ol {
    margin: 0 0 12px 0;
    padding-left: 1.3em;
}
.product-description li {
    margin-bottom: 4px;
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

            <?php if (!empty($error)): ?>
                <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Domain Configuration Section -->
            <?php if (!empty($product['require_domain'])): ?>
                <div class="form-section">
                    <h2 class="form-section__title">
                        <span class="form-section__title-icon">🌐</span>
                        Domain Configuration
                    </h2>

                    <div class="domain-options">
                        <?php foreach ([
                            ['value' => 'register', 'icon' => '✨', 'title' => 'Register a new domain name', 'hint' => 'Search for an available name and register it with your order.'],
                            ['value' => 'transfer', 'icon' => '↔️', 'title' => 'Transfer your domain', 'hint' => 'Move an existing domain here from another registrar.'],
                            ['value' => 'existing', 'icon' => '🔗', 'title' => 'Use my existing domain', 'hint' => "Keep it where it is and point the nameservers at us."],
                        ] as $choice): ?>
                            <label class="domain-option">
                                <input type="radio" name="domain_option" value="<?= e($choice['value']) ?>" <?= $choice['value'] === 'register' ? 'checked' : '' ?> data-domain-option-toggle>
                                <span class="domain-option__icon" aria-hidden="true"><?= $choice['icon'] ?></span>
                                <span class="domain-option__text">
                                    <span class="domain-option__title"><?= e($choice['title']) ?></span>
                                    <span class="domain-option__hint"><?= e($choice['hint']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div id="domain-input-wrapper" style="margin-bottom: 20px;" data-tld-options="<?= e(json_encode($domainTlds)) ?>">
                        <div class="domain-field">
                            <span class="domain-field__prefix">www.</span>
                            <input class="domain-field__input" type="text" name="domain_name" placeholder="yourbusiness" required data-domain-availability-input>
                            <span class="domain-field__divider" aria-hidden="true"></span>
                            <select class="domain-field__tld" name="domain_tld" aria-label="Domain extension" data-domain-availability-tld>
                                <?php foreach ($domainTlds as $tld): ?>
                                    <option value="<?= e($tld) ?>"><?= e($tld) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="domain-result" data-domain-availability-result></div>
                    </div>

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
                        <?php
                        // white-space:pre-wrap kept line breaks but rendered any HTML an
                        // admin wrote as raw text, and preserved stray indentation.
                        // FormattedText builds real paragraphs instead — so pre-wrap has
                        // to go, or the newlines it keeps would double the spacing.
                        ?>
                        <div data-accordion-content class="product-description" style="color: var(--cv-text-secondary); font-size: .95rem; line-height: 1.6; padding-top: 12px;">
                            <?= CodeVault\Support\FormattedText::toHtml((string) $product['description']) ?>
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

<?php
// The "View Product Details" accordion is driven from public/assets/js/app.js
// behind a [data-accordion-trigger] delegated listener, NOT from an inline
// <script> here. SecurityHeaders sends script-src 'self' with no
// 'unsafe-inline', so an inline block on this page is silently blocked by the
// browser and the button does nothing at all — which is exactly what happened
// before. Keep page behaviour in app.js.
?>
