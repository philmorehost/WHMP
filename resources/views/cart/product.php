<?php
/** @var array<string, mixed> $product */
/** @var array<string, array<string, mixed>> $pricing */
/** @var array<string, string> $cycles */
/** @var array<int, array<string, mixed>> $optionGroups */
/** @var array<string, mixed> $currency */
/** @var CodeVault\Localization\Translation $t */
/** @var array<int, string> $defaultNameservers */
/** @var array<int, string> $domainTlds */
$money = static fn (float $amount): string => $currency['symbol'] . number_format($amount * (float) $currency['exchange_rate'], 2);
$domainTlds = $domainTlds !== [] ? $domainTlds : ['.com', '.net', '.org'];
?>
<div class="cv-card" style="max-width:38rem;margin:0 auto;padding:var(--cv-space-5);border-radius:var(--cv-radius-lg, 12px);">
    <form method="post" action="/cart/add" id="product-order-form"><?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

        <!-- 1. Domain Fields at the Top -->
        <?php if (!empty($product['require_domain'])): ?>
            <div class="cv-card" style="border:1px solid var(--cv-border-default); padding:var(--cv-space-4); margin-bottom:var(--cv-space-4); background:var(--cv-bg-surface-sunken); border-radius:var(--cv-radius-md);">
                <h3 style="margin-top:0; font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md);">Configure Domain...</h3>
                <div style="display:flex; flex-direction:column; gap:var(--cv-space-2); margin-bottom:var(--cv-space-4);">
                    <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer;">
                        <input type="radio" name="domain_option" value="register" checked data-domain-option-toggle>
                        Register a new domain name
                    </label>
                    <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer;">
                        <input type="radio" name="domain_option" value="transfer" data-domain-option-toggle>
                        Transfer your domain from another registrar
                    </label>
                    <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer;">
                        <input type="radio" name="domain_option" value="existing" data-domain-option-toggle>
                        I will use my existing domain and update nameservers
                    </label>
                </div>

                <div id="domain-input-wrapper" style="margin-bottom:var(--cv-space-3);" data-tld-options="<?= e(json_encode($domainTlds)) ?>">
                    <div style="display:flex; gap:var(--cv-space-2); align-items:center;">
                        <span style="font-weight:600; color:var(--cv-text-secondary);">www.</span>
                        <input class="cv-input" type="text" name="domain_name" placeholder="example" required style="flex:1;" data-domain-availability-input>
                        <select class="cv-select" name="domain_tld" style="width:100px;" data-domain-availability-tld>
                            <?php foreach ($domainTlds as $tld): ?>
                                <option value="<?= e($tld) ?>"><?= e($tld) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div data-domain-availability-result style="font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);"></div>
                </div>

                <div id="nameserver-wrapper" style="display:none; transition: all 0.2s ease-in-out;">
                    <label class="cv-label" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Nameservers</span>
                        <span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);">Using default nameservers</span>
                    </label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--cv-space-2);">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <input class="cv-input" name="ns<?= $i ?>" value="<?= e($defaultNameservers[$i - 1] ?? '') ?>" placeholder="ns<?= $i ?>.yournameservers.com<?= $i > 2 ? ' (optional)' : '' ?>">
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. Product Title & Accordion Description -->
        <div style="border: 1px solid var(--cv-border-default); border-radius: var(--cv-radius-md); padding: var(--cv-space-4); margin-bottom: var(--cv-space-4); background: var(--cv-bg-surface);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin:0; font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-lg);"><?= e($product['name']) ?></h2>
                <a href="/store" style="font-size:var(--cv-text-sm); text-decoration:none; color:var(--cv-color-primary, #2563eb);">&larr; <?= e($t->get('product.back_to_store')) ?></a>
            </div>

            <!-- Accordion Details -->
            <?php if (!empty($product['description'])): ?>
                <div style="margin-top: var(--cv-space-3);" data-details-accordion>
                    <button type="button" style="width: 100%; display: flex; justify-content: space-between; align-items: center; background: none; border: none; padding: var(--cv-space-2) 0; color: var(--cv-color-primary); font-weight: 600; cursor: pointer; font-size: var(--cv-text-sm); text-align: left;" data-accordion-trigger>
                        <span>View Product Details</span>
                        <svg style="width: 16px; height: 16px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24" data-accordion-icon><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;" data-accordion-content>
                        <div style="padding-top: var(--cv-space-2); color: var(--cv-text-secondary); font-size: var(--cv-text-sm); line-height: 1.5; white-space: pre-wrap;"><?= e((string) $product['description']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($pricing === []): ?>
            <p style="color:var(--cv-text-secondary);"><?= e($t->get('product.not_available')) ?></p>
        <?php else: ?>
            <!-- Billing Cycle -->
            <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
                <label class="cv-label"><?= e($t->get('product.billing_cycle')) ?></label>
                <div style="display:flex; flex-direction:column; gap:var(--cv-space-2);">
                    <?php $first = true; foreach ($pricing as $cycleKey => $row): ?>
                        <label style="display:flex; align-items:center; gap:var(--cv-space-2); cursor:pointer; padding:var(--cv-space-2); border:1px solid var(--cv-border-default); border-radius:var(--cv-radius-sm); background:var(--cv-bg-surface);">
                            <input type="radio" name="billing_cycle" value="<?= e($cycleKey) ?>" required <?= $first ? 'checked' : '' ?>>
                            <span>
                                <strong><?= e($cycles[$cycleKey] ?? $cycleKey) ?></strong> —
                                <?php if ((float) $row['setup_fee'] > 0): ?>
                                    <?= $money((float) $row['setup_fee']) ?> <?= e($t->get('product.setup')) ?> +
                                <?php endif; ?>
                                <?= $money((float) $row['price']) ?>
                            </span>
                        </label>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>

            <?php if (!empty($customFields)): ?>
                <div class="cv-card" style="border:1px solid var(--cv-border-default); padding:var(--cv-space-4); margin-bottom:var(--cv-space-4); background:var(--cv-bg-surface-sunken); border-radius:var(--cv-radius-md);">
                    <h3 style="margin-top:0; font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md);">Additional Information</h3>
                    <?php foreach ($customFields as $field): ?>
                        <div class="cv-field">
                            <label class="cv-label"><?= e($field['name']) ?> <?= $field['required'] ? '<span style="color:var(--cv-color-danger, #ef4444);">*</span>' : '' ?></label>
                            <?php if ($field['type'] === 'textarea'): ?>
                                <textarea class="cv-textarea" name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>></textarea>
                            <?php elseif ($field['type'] === 'dropdown'): ?>
                                <select class="cv-select" name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>>
                                    <?php foreach (explode(',', (string) $field['options']) as $opt): ?>
                                        <option value="<?= e(trim($opt)) ?>"><?= e(trim($opt)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['type'] === 'checkbox'): ?>
                                <label style="display:flex; align-items:center; gap:var(--cv-space-2);">
                                    <input type="checkbox" name="custom_field[<?= (int) $field['id'] ?>]" value="1">
                                    Yes
                                </label>
                            <?php else: ?>
                                <input class="cv-input" type="<?= $field['type'] === 'password' ? 'password' : 'text' ?>" name="custom_field[<?= (int) $field['id'] ?>]" <?= $field['required'] ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (in_array($product['type'] ?? '', ['vps', 'dedicated'], true)): ?>
                <div class="cv-card" style="border:1px solid var(--cv-border-default); padding:var(--cv-space-4); margin-bottom:var(--cv-space-4); background:var(--cv-bg-surface-sunken); border-radius:var(--cv-radius-md);">
                    <h3 style="margin-top:0; font-family:'Hanken Grotesk',sans-serif; font-size:var(--cv-text-md);">Server Configuration</h3>
                    <div class="cv-field">
                        <label class="cv-label">Hostname <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                        <input class="cv-input" type="text" name="hostname" placeholder="server1.yourdomain.com" required style="width: 100%;">
                    </div>
                    <div class="cv-field">
                        <label class="cv-label">Root Password <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                        <input class="cv-input" type="password" name="root_password" placeholder="Choose a secure root password" required style="width: 100%;">
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($optionGroups as $og): ?>
                <div class="cv-field">
                    <label class="cv-label"><?= e($og['name']) ?></label>
                    <select class="cv-select" name="option[<?= (int) $og['id'] ?>]">
                        <option value="">None</option>
                        <?php foreach ($og['options'] as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"><?= e($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
                <label class="cv-label"><?= e($t->get('product.quantity')) ?></label>
                <input class="cv-input" type="number" name="quantity" value="1" min="1" style="width:6rem;">
            </div>

            <button class="cv-btn" type="submit" style="width: 100%; padding: var(--cv-space-3); font-size: var(--cv-text-md); font-weight: 600;"><?= e($t->get('product.add_to_cart')) ?></button>
        <?php endif; ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Accordion Toggle Logic (Auto-close on click elsewhere)
    const trigger = document.querySelector('[data-accordion-trigger]');
    const content = document.querySelector('[data-accordion-content]');
    const icon = document.querySelector('[data-accordion-icon]');
    const accordionContainer = document.querySelector('[data-details-accordion]');

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
            e.stopPropagation();
            if (content.style.maxHeight && content.style.maxHeight !== '0px') {
                closeAccordion();
            } else {
                openAccordion();
            }
        });

        document.addEventListener('click', function(e) {
            if (accordionContainer && !accordionContainer.contains(e.target)) {
                closeAccordion();
            }
        });
    }

    // 2. Dynamically Hide/Show Nameservers for Domain Registration/Transfer
    const domainOptions = document.querySelectorAll('[data-domain-option-toggle]');
    const nsWrapper = document.getElementById('nameserver-wrapper');

    if (domainOptions.length > 0 && nsWrapper) {
        function updateNsVisibility() {
            const selected = document.querySelector('[data-domain-option-toggle]:checked').value;
            // Hide nameservers (and use defaults) if registering/transferring domain along with hosting.
            // Show only if client chooses "I will use my existing domain and update nameservers" (existing).
            if (selected === 'register' || selected === 'transfer') {
                nsWrapper.style.display = 'none';
            } else {
                nsWrapper.style.display = 'block';
            }
        }

        domainOptions.forEach(opt => opt.addEventListener('change', updateNsVisibility));
        updateNsVisibility(); // run once on init
    }
});
</script>
