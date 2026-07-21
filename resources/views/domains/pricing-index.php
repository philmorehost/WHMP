<?php
/** @var array<int, array<string, mixed>> $pricingList */
/** @var array<int, array<string, mixed>> $registrars */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Domain TLD Pricing</h1>
    <p><a href="/admin/domains">&larr; Back to domains</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <h2 class="cv-card__title" id="tld-form-title">Add or Update TLD</h2>
    <form id="tld-form" method="post" action="/admin/domain-pricing" style="display:grid;grid-template-columns:repeat(6, 1fr);gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">TLD (e.g. .com)</label>
            <input class="cv-input" name="tld" id="tld-input" placeholder=".com" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Category (tab)</label>
            <input class="cv-input" name="category" id="tld-category" list="tld-category-list" placeholder="Popular" value="Popular">
            <datalist id="tld-category-list">
                <option value="Popular"></option>
                <option value="Geographic"></option>
                <option value="Technology"></option>
                <option value="Shopping"></option>
                <option value="Novelty"></option>
                <option value="Other"></option>
            </datalist>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Registrar</label>
            <select class="cv-select" name="registrar_slug" id="tld-registrar" required>
                <?php foreach ($registrars as $registrar): ?>
                    <option value="<?= e($registrar['slug']) ?>"><?= e($registrar['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Register Price</label>
            <input class="cv-input" type="number" step="0.01" name="register_price" id="tld-register-price" value="0.00" min="0">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Transfer Price</label>
            <input class="cv-input" type="number" step="0.01" name="transfer_price" id="tld-transfer-price" value="0.00" min="0">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Renewal Price</label>
            <input class="cv-input" type="number" step="0.01" name="renew_price" id="tld-renew-price" value="0.00" min="0">
        </div>
        <div class="cv-field" style="margin-bottom:0;grid-column:span 3;">
            <label class="cv-label">Registration Auto-Setup</label>
            <select class="cv-select" name="autosetup_registration" id="tld-autosetup-registration">
                <option value="order">Automatically setup as soon as an order is placed</option>
                <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                <option value="on_accept">Automatically setup when manually accepting pending order</option>
                <option value="off">Do not automatically setup</option>
            </select>
        </div>
        <div class="cv-field" style="margin-bottom:0;grid-column:span 3;">
            <label class="cv-label">Transfer Auto-Setup</label>
            <select class="cv-select" name="autosetup_transfer" id="tld-autosetup-transfer">
                <option value="order">Automatically setup as soon as an order is placed</option>
                <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                <option value="on_accept">Automatically setup when manually accepting pending order</option>
                <option value="off">Do not automatically setup</option>
            </select>
        </div>
        <div class="cv-field" style="margin-bottom:0;grid-column:span 6;">
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                <input type="checkbox" name="spinner_enabled" id="tld-spinner-enabled">
                Allow this TLD in the Domain Spinner (client-facing name-suggestion tool)
            </label>
        </div>
        <div style="grid-column:span 6;margin-top:var(--cv-space-2);display:flex;gap:var(--cv-space-2);">
            <button class="cv-btn" type="submit" data-edit-submit>Save TLD Pricing</button>
            <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
                data-edit-cancel
                data-edit-reset-action="/admin/domain-pricing"
                data-edit-reset-label="Save TLD Pricing"
                data-edit-reset-title="Add or Update TLD"
                data-edit-title-target="#tld-form-title">Cancel</button>
        </div>
    </form>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">TLD Pricing Table</h2>
        <?= $view->partial('partials.table-search', ['target' => '#tld-pricing-table', 'placeholder' => 'Search TLDs...']) ?>
    </div>
    <table class="cv-table" id="tld-pricing-table">
        <thead>
            <tr>
                <th>TLD</th>
                <th>Category</th>
                <th>Registrar</th>
                <th>Register Price</th>
                <th>Transfer Price</th>
                <th>Renewal Price</th>
                <th>Spinner</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pricingList as $price): ?>
                <tr>
                    <td><strong><?= e($price['tld']) ?></strong></td>
                    <td><span class="cv-badge cv-badge--neutral"><?= e($price['category'] ?? 'Popular') ?></span></td>
                    <td><span class="cv-badge cv-badge--neutral"><?= e($price['registrar_slug']) ?></span></td>
                    <td>$<?= number_format((float) $price['register_price'], 2) ?></td>
                    <td>$<?= number_format((float) $price['transfer_price'], 2) ?></td>
                    <td>$<?= number_format((float) $price['renew_price'], 2) ?></td>
                    <td>
                        <?php if (!empty($price['spinner_enabled'])): ?>
                            <span class="cv-badge cv-badge--success">Enabled</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--neutral">Off</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:var(--cv-space-2);">
                        <button type="button" class="cv-btn cv-btn--secondary"
                            data-edit-trigger
                            data-edit-form="#tld-form"
                            data-edit-fields="<?= e(json_encode([
                                'tld' => $price['tld'],
                                'category' => $price['category'] ?? 'Popular',
                                'registrar_slug' => $price['registrar_slug'],
                                'register_price' => number_format((float) $price['register_price'], 2, '.', ''),
                                'transfer_price' => number_format((float) $price['transfer_price'], 2, '.', ''),
                                'renew_price' => number_format((float) $price['renew_price'], 2, '.', ''),
                                'autosetup_registration' => $price['autosetup_registration'] ?? 'payment',
                                'autosetup_transfer' => $price['autosetup_transfer'] ?? 'payment',
                                'spinner_enabled' => !empty($price['spinner_enabled']),
                            ])) ?>"
                            data-edit-action="/admin/domain-pricing"
                            data-edit-submit-label="Update TLD Pricing"
                            data-edit-title="Edit TLD &mdash; <?= e($price['tld']) ?>"
                            data-edit-title-target="#tld-form-title">Edit</button>
                        <form method="post" action="/admin/domain-pricing/<?= (int) $price['id'] ?>/delete" style="display:inline;" data-confirm="Are you sure you want to delete this TLD?"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($pricingList === []): ?>
                <tr>
                    <td colspan="8" style="text-align:center;color:var(--cv-text-secondary);padding:var(--cv-space-4);">No domain TLD pricing configured yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
