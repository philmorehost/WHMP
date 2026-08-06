<?php
/** @var array<int, array<string, mixed>> $clients */
/** @var array<int, array<string, mixed>> $products */
/** @var array<string, string> $cycles */
/** @var array<int, string> $domainTlds */
/** @var array<int, string> $defaultNameservers */
/** @var string|null $error */
/** @var array{client_id: string|int, is_existing?: bool} $old */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Create Order</h1>
    <p><a href="/admin/orders">&larr; Back to orders</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:0;">
        Places an order on a client's behalf — products, a domain registration, or both. The order
        is created pending, exactly like a storefront order, and the client is emailed the invoice
        in their own currency.
    </p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/orders"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label" for="order-client-search">Client</label>
            <div data-client-picker>
                <input type="hidden" name="client_id" id="order-client-id" value="<?= e((string) ($old['client_id'] ?? '')) ?>" data-client-id-input>
                <input type="text" id="order-client-search" class="cv-input" autocomplete="off"
                       placeholder="Type to search clients by name, email or company…"
                       data-client-search-input>
                <div data-client-results style="display:none;border:1px solid var(--cv-border-default);border-radius:6px;margin-top:4px;max-height:220px;overflow-y:auto;"></div>
                <small style="color:var(--cv-text-secondary);display:block;margin-top:4px;" data-client-picker-hint>
                    Start typing — the selected client appears here.
                </small>
            </div>
        </div>

        <div class="cv-field" style="margin-top:var(--cv-space-2);">
            <label style="display:flex;gap:var(--cv-space-2);align-items:flex-start;cursor:pointer;font-size:var(--cv-text-sm);">
                <input type="checkbox" name="is_existing" value="1" id="order-is-existing" <?= !empty($old['is_existing']) ? 'checked' : '' ?>>
                <span>
                    <strong>Existing service / domain</strong>
                    <span style="color:var(--cv-text-secondary);display:block;">
                        The service or domain already exists (e.g. moved over from another system). No invoice
                        is generated — the product/domain price is still recorded on the order so its value shows
                        everywhere. Orders created this way land as <strong>active</strong>.
                    </span>
                </span>
            </label>
        </div>

        <h3 style="font-size:var(--cv-text-base);margin:var(--cv-space-4) 0 var(--cv-space-2);">Products</h3>

        <div data-order-items>
            <div style="display:grid;grid-template-columns:2fr 1fr 90px 40px;gap:var(--cv-space-2);margin-bottom:var(--cv-space-2);align-items:center;" data-order-item-row>
                <select class="cv-select" name="product_id[]">
                    <option value="">— No product —</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= e((string) $product['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="cv-select" name="billing_cycle[]">
                    <?php foreach ($cycles as $cycleKey => $cycleLabel): ?>
                        <option value="<?= e($cycleKey) ?>"><?= e($cycleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <input class="cv-input" type="number" min="1" step="1" name="quantity[]" value="1">
                <button type="button" class="cv-btn cv-btn--secondary" data-remove-order-item title="Remove line" style="padding:6px 10px;">&times;</button>
            </div>
        </div>

        <button type="button" class="cv-btn cv-btn--secondary" data-add-order-item style="margin-bottom:var(--cv-space-3);">+ Add product</button>

        <h3 style="font-size:var(--cv-text-base);margin:var(--cv-space-4) 0 var(--cv-space-2);">Domain (optional)</h3>

        <div class="domain-options">
            <?php foreach ([
                ['value' => '', 'icon' => '—', 'title' => 'No domain', 'hint' => "This order doesn't include a domain registration."],
                ['value' => 'register', 'icon' => '✨', 'title' => 'Register a new domain name', 'hint' => 'Registers an available name as part of this order.'],
                ['value' => 'transfer', 'icon' => '↔️', 'title' => 'Transfer a domain', 'hint' => 'Move an existing domain here from another registrar.'],
                ['value' => 'existing', 'icon' => '🔗', 'title' => "Use the client's existing domain", 'hint' => "Keep it where it is and point the nameservers at us."],
            ] as $choice): ?>
                <label class="domain-option">
                    <input type="radio" name="domain_option" value="<?= e($choice['value']) ?>" <?= $choice['value'] === '' ? 'checked' : '' ?> data-domain-option-toggle>
                    <span class="domain-option__icon" aria-hidden="true"><?= $choice['icon'] ?></span>
                    <span class="domain-option__text">
                        <span class="domain-option__title"><?= e($choice['title']) ?></span>
                        <span class="domain-option__hint"><?= e($choice['hint']) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div id="domain-input-wrapper" style="margin-bottom: 20px;display:none;" data-tld-options="<?= e(json_encode($domainTlds)) ?>">
            <div class="domain-field">
                <span class="domain-field__prefix">www.</span>
                <input class="domain-field__input" type="text" name="domain_name" placeholder="yourbusiness" data-domain-availability-input>
                <span class="domain-field__divider" aria-hidden="true"></span>
                <select class="domain-field__tld" name="domain_tld" aria-label="Domain extension" data-domain-availability-tld>
                    <?php foreach ($domainTlds as $tld): ?>
                        <option value="<?= e($tld) ?>"><?= e($tld) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="domain-result" data-domain-availability-result></div>
        </div>

        <div id="nameserver-wrapper" style="display: none;">
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

        <div style="border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
            <button class="cv-btn cv-btn--primary" type="submit">Create Order</button>
            <a href="/admin/orders" class="cv-btn cv-btn--secondary">Cancel</a>
        </div>
    </form>
</div>
