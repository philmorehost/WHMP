<?php
/** @var array<int, array<string, mixed>> $pricingList */
/** @var array<int, array<string, mixed>> $registrars */
/** @var string|null $error */
/** @var int $spinnerEnabledCount */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Domain TLD Pricing</h1>
    <p><a href="/admin/domains">&larr; Back to domains</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-bottom:var(--cv-space-3);flex-wrap:wrap;">
        <button type="button" id="toggle-bulk-form" class="cv-btn cv-btn--secondary" style="cursor:pointer;">📋 Bulk Add Multiple TLDs</button>
        <button type="button" id="toggle-whmcs-form" class="cv-btn cv-btn--secondary" style="cursor:pointer;">📦 Import from WHMCS</button>
    </div>

    <div id="bulk-form-section" style="display:none;margin-bottom:var(--cv-space-4);padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);border-radius:8px;">
        <h3 style="margin:0 0 var(--cv-space-3) 0;">Bulk Add Multiple TLDs</h3>
        <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin:0 0 var(--cv-space-2) 0;">Enter multiple TLDs separated by commas or newlines. All will be added with the same registrar and pricing.</p>
        <style>
        #bulk-tld-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: var(--cv-space-3);
            align-items: start;
        }
        @media (max-width: 1100px) {
            #bulk-tld-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            #bulk-tld-form .bulk-span4 { grid-column: span 2; }
        }
        @media (max-width: 700px) {
            #bulk-tld-form { grid-template-columns: 1fr; }
            #bulk-tld-form .bulk-span4 { grid-column: span 1; }
        }
        </style>
        <form id="bulk-tld-form" method="post" action="/admin/domain-pricing/bulk"><?= csrf_field() ?>
            <div class="cv-field bulk-span4" style="margin-bottom:0;grid-column:span 4;">
                <label class="cv-label">TLD List (comma or newline separated)</label>
                <textarea class="cv-input" name="tld_list" placeholder=".com, .net, .org&#10;.info&#10;.biz" style="min-height:120px;font-family:monospace;font-size:.85rem;" required></textarea>
                <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">Examples: .com, .net, .org or paste one per line</div>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Registrar</label>
                <select class="cv-select" name="registrar_slug" required>
                    <?php foreach ($registrars as $registrar): ?>
                        <option value="<?= e($registrar['slug']) ?>"><?= e($registrar['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Register Price</label>
                <input class="cv-input" type="number" step="0.01" name="register_price" value="0.00" min="0">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Transfer Price</label>
                <input class="cv-input" type="number" step="0.01" name="transfer_price" value="0.00" min="0">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Renewal Price</label>
                <input class="cv-input" type="number" step="0.01" name="renew_price" value="0.00" min="0">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Category</label>
                <input class="cv-input" name="category" list="bulk-category-list" placeholder="Popular" value="Popular">
                <datalist id="bulk-category-list">
                    <option value="Popular"></option>
                    <option value="Geographic"></option>
                    <option value="Technology"></option>
                    <option value="Shopping"></option>
                    <option value="Novelty"></option>
                    <option value="Other"></option>
                </datalist>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Grace Period (Days)</label>
                <input class="cv-input" type="number" name="grace_period_days" value="30" min="0">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Redemption Period (Days)</label>
                <input class="cv-input" type="number" name="redemption_period_days" value="30" min="0">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Redemption Fee</label>
                <input class="cv-input" type="number" step="0.01" name="redemption_fee" value="0.00" min="0">
            </div>
            <div class="cv-field bulk-span4" style="margin-bottom:0;grid-column:span 4;">
                <label class="cv-label">Registration Auto-Setup</label>
                <select class="cv-select" name="autosetup_registration">
                    <option value="order">Automatically setup as soon as an order is placed</option>
                    <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                    <option value="on_accept">Automatically setup when manually accepting pending order</option>
                    <option value="off">Do not automatically setup</option>
                </select>
            </div>
            <div class="cv-field bulk-span4" style="margin-bottom:0;grid-column:span 4;">
                <label class="cv-label">Transfer Auto-Setup</label>
                <select class="cv-select" name="autosetup_transfer">
                    <option value="order">Automatically setup as soon as an order is placed</option>
                    <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                    <option value="on_accept">Automatically setup when manually accepting pending order</option>
                    <option value="off">Do not automatically setup</option>
                </select>
            </div>
            <div class="cv-field bulk-span4" style="margin-bottom:0;grid-column:span 4;">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="spinner_enabled">
                    Allow these TLDs in the Domain Spinner
                </label>
            </div>
            <div class="bulk-span4" style="grid-column:span 4;display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
                <button class="cv-btn" type="submit">✓ Add All TLDs</button>
                <button type="button" class="cv-btn cv-btn--secondary" data-toggle-hide="#bulk-form-section">Cancel</button>
            </div>
        </form>
    </div>

    <div id="whmcs-form-section" style="display:none;margin-bottom:var(--cv-space-4);padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);border-radius:8px;">
        <h3 style="margin:0 0 var(--cv-space-3) 0;">Import Domain Extensions from WHMCS</h3>
        <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin:0 0 var(--cv-space-3) 0;">Select domain extensions from your WHMCS database and assign them all to a registrar with pricing.</p>

        <div style="margin-bottom:var(--cv-space-3);padding:var(--cv-space-2);background:var(--cv-bg-surface);border-radius:6px;">
            <button type="button" id="fetch-whmcs-extensions" class="cv-btn cv-btn--secondary" style="margin-bottom:var(--cv-space-2);">🔄 Fetch WHMCS Extensions</button>
            <div id="whmcs-extensions-loading" style="display:none;font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">Loading extensions from WHMCS...</div>
            <div id="whmcs-extensions-list" style="display:none;margin-top:var(--cv-space-2);max-height:300px;overflow-y:auto;border:1px solid var(--cv-border-default);border-radius:6px;padding:var(--cv-space-2);">
                <!-- Extensions will be populated here -->
            </div>
            <div id="whmcs-error" style="display:none;color:#ef4444;font-size:var(--cv-text-sm);margin-top:var(--cv-space-2);"></div>
        </div>

        <form id="whmcs-import-form" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--cv-space-3);align-items:start;">
            <?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 1;">
                <label class="cv-label">Registrar</label>
                <select class="cv-select" name="registrar_slug" required>
                    <?php foreach ($registrars as $registrar): ?>
                        <option value="<?= e($registrar['slug']) ?>"><?= e($registrar['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 1;">
                <label class="cv-label">Register Price</label>
                <input class="cv-input" type="number" step="0.01" name="register_price" value="0.00" min="0" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 1;">
                <label class="cv-label">Transfer Price</label>
                <input class="cv-input" type="number" step="0.01" name="transfer_price" value="0.00" min="0" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 1;">
                <label class="cv-label">Renewal Price</label>
                <input class="cv-input" type="number" step="0.01" name="renew_price" value="0.00" min="0" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 2;">
                <label class="cv-label">Category</label>
                <input class="cv-input" name="category" list="whmcs-category-list" placeholder="Popular" value="Popular">
                <datalist id="whmcs-category-list">
                    <option value="Popular"></option>
                    <option value="Geographic"></option>
                    <option value="Technology"></option>
                    <option value="Shopping"></option>
                    <option value="Novelty"></option>
                    <option value="Other"></option>
                </datalist>
            </div>
            <div class="cv-field" style="margin-bottom:0;grid-column:span 2;">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;margin-top:var(--cv-space-2);">
                    <input type="checkbox" name="spinner_enabled">
                    Allow in Domain Spinner
                </label>
            </div>
            <div style="grid-column:span 4;display:flex;gap:var(--cv-space-2);flex-wrap:wrap;margin-top:var(--cv-space-2);">
                <button class="cv-btn" type="submit" id="import-whmcs-btn" disabled>✓ Import Selected Extensions</button>
                <button type="button" class="cv-btn cv-btn--secondary" data-toggle-hide="#whmcs-form-section">Cancel</button>
            </div>
        </form>
    </div>

    <h2 class="cv-card__title" id="tld-form-title">Add or Update Single TLD</h2>
    <style>
    #tld-form {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: var(--cv-space-3);
        align-items: end;
    }
    @media (max-width: 1100px) {
        #tld-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        #tld-form .tld-span3 { grid-column: span 3; }
        #tld-form .tld-span6 { grid-column: span 3; }
    }
    @media (max-width: 700px) {
        #tld-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #tld-form .tld-span3,
        #tld-form .tld-span6 { grid-column: span 2; }
    }
    @media (max-width: 420px) {
        #tld-form { grid-template-columns: 1fr; }
        #tld-form .tld-span3,
        #tld-form .tld-span6 { grid-column: span 1; }
    }
    </style>
    <form id="tld-form" method="post" action="/admin/domain-pricing"><?= csrf_field() ?>
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
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Grace Period (Days)</label>
            <input class="cv-input" type="number" name="grace_period_days" id="tld-grace-period" value="30" min="0">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Redemption Period (Days)</label>
            <input class="cv-input" type="number" name="redemption_period_days" id="tld-redemption-period" value="30" min="0">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Redemption Fee</label>
            <input class="cv-input" type="number" step="0.01" name="redemption_fee" id="tld-redemption-fee" value="0.00" min="0">
        </div>
        <div class="cv-field tld-span3" style="margin-bottom:0;grid-column:span 3;">
            <label class="cv-label">Registration Auto-Setup</label>
            <select class="cv-select" name="autosetup_registration" id="tld-autosetup-registration">
                <option value="order">Automatically setup as soon as an order is placed</option>
                <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                <option value="on_accept">Automatically setup when manually accepting pending order</option>
                <option value="off">Do not automatically setup</option>
            </select>
        </div>
        <div class="cv-field tld-span3" style="margin-bottom:0;grid-column:span 3;">
            <label class="cv-label">Transfer Auto-Setup</label>
            <select class="cv-select" name="autosetup_transfer" id="tld-autosetup-transfer">
                <option value="order">Automatically setup as soon as an order is placed</option>
                <option value="payment" selected>Automatically setup as soon as first payment is received</option>
                <option value="on_accept">Automatically setup when manually accepting pending order</option>
                <option value="off">Do not automatically setup</option>
            </select>
        </div>
        <div class="cv-field tld-span6" style="margin-bottom:0;grid-column:span 6;">
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                <input type="checkbox" name="spinner_enabled" id="tld-spinner-enabled">
                Allow this TLD in the Domain Spinner (client-facing name-suggestion tool)
            </label>
        </div>
        <div class="tld-span6" style="grid-column:span 6;margin-top:var(--cv-space-2);display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
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

<?php if (($bulkUpdated ?? null) !== null): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        <?= (int) $bulkUpdated ?> TLD<?= (int) $bulkUpdated === 1 ? '' : 's' ?> updated.
    </div>
<?php endif; ?>

<?php if (($reordered ?? false)): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        Display order saved.
    </div>
<?php endif; ?>

<?php
// "Spinner" is a separate, deliberate opt-in per TLD (default off — migration
// 0106) from having the TLD priced and listed here. That means an install can
// have every TLD below fully priced and still send zero suggestions from the
// storefront's "Suggested Alternatives" search, because nobody separately
// flipped this flag — which looks exactly like a broken feature from the
// client side, with nothing on the storefront pointing back to this page as
// the cause. Surfaced here, where the setting actually lives, with a one-click
// fix rather than sending the admin hunting through the per-row edit form or
// the bulk-select-then-choose-Enable-then-Apply flow further down the page.
?>
<?php if ($spinnerEnabledCount === 0 && $pricingList !== []): ?>
    <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:var(--cv-space-3) var(--cv-space-4);margin-bottom:var(--cv-space-3);display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-3);flex-wrap:wrap;">
        <div>
            <strong style="color:var(--cv-text-primary);">The Domain Spinner is off for every TLD.</strong>
            <p style="margin:4px 0 0;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                Clients searching for a taken domain on the storefront get no "Suggested Alternatives" until at least
                one TLD below is spinner-enabled — pricing a TLD here does not enable it on its own.
            </p>
        </div>
        <form method="post" action="/admin/domain-pricing/bulk-update" style="flex-shrink:0;">
            <?= csrf_field() ?>
            <?php foreach ($pricingList as $price): ?>
                <input type="hidden" name="ids[]" value="<?= (int) $price['id'] ?>">
            <?php endforeach; ?>
            <input type="hidden" name="spinner_enabled" value="1">
            <button type="submit" class="cv-btn cv-btn--primary" style="white-space:nowrap;">Enable Spinner for All <?= count($pricingList) ?> TLDs</button>
        </form>
    </div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">TLD Pricing Table</h2>
        <?= $view->partial('partials.table-search', ['target' => '#tld-pricing-table', 'placeholder' => 'Search TLDs...']) ?>
    </div>

    <?php
    // Display order for the TLD tabs/lists a client sees. Each row's number
    // input lives inside the table body (a <form> can't wrap <tbody> rows
    // without breaking table markup) and points at this one via
    // form="tld-reorder-form" — same cross-referencing technique the
    // bulk-select checkboxes below already use.
    ?>
    <form method="post" action="/admin/domain-pricing/reorder" id="tld-reorder-form" style="margin-bottom:var(--cv-space-2);display:flex;justify-content:flex-end;">
        <?= csrf_field() ?>
        <button type="submit" class="cv-btn cv-btn--secondary">💾 Save Display Order</button>
    </form>

    <?php
    // Bulk editor. Every field means "leave unchanged" when blank, so an admin
    // can repoint 40 TLDs at a new registrar without disturbing their prices.
    // The row checkboxes live inside the table but belong to this form via
    // form="tld-bulk-update-form" — a <form> can't wrap <tbody> rows without
    // breaking table markup.
    ?>
    <form method="post" action="/admin/domain-pricing/bulk-update" id="tld-bulk-update-form"
          data-tld-bulk-form hidden
          style="border:1px solid var(--cv-border-default);border-radius:10px;padding:var(--cv-space-3);margin-bottom:var(--cv-space-3);background:var(--cv-bg-surface-sunken);">
        <?= csrf_field() ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-2);flex-wrap:wrap;margin-bottom:var(--cv-space-2);">
            <strong><span data-tld-selected-count>0</span> TLD(s) selected — edit together</strong>
            <span style="font-size:.8rem;color:var(--cv-text-secondary);">Leave a field blank to keep its current value.</span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--cv-space-2);align-items:end;">
            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Category</span>
                <input class="cv-input" type="text" name="category" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Registrar</span>
                <select class="cv-select" name="registrar_slug">
                    <option value="">Unchanged</option>
                    <?php foreach ($registrars as $registrar): ?>
                        <option value="<?= e($registrar['slug']) ?>"><?= e($registrar['name'] ?? $registrar['slug']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Register Price</span>
                <input class="cv-input" type="number" step="0.01" min="0" name="register_price" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Transfer Price</span>
                <input class="cv-input" type="number" step="0.01" min="0" name="transfer_price" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Renewal Price</span>
                <input class="cv-input" type="number" step="0.01" min="0" name="renew_price" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Grace Period (Days)</span>
                <input class="cv-input" type="number" min="0" name="grace_period_days" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Redemption Period (Days)</span>
                <input class="cv-input" type="number" min="0" name="redemption_period_days" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Redemption Fee</span>
                <input class="cv-input" type="number" step="0.01" min="0" name="redemption_fee" placeholder="Unchanged">
            </label>

            <label style="display:block;">
                <span style="font-size:.8rem;color:var(--cv-text-secondary);">Spinner</span>
                <select class="cv-select" name="spinner_enabled">
                    <option value="">Unchanged</option>
                    <option value="1">Enable</option>
                    <option value="0">Disable</option>
                </select>
            </label>

            <div style="display:flex;gap:var(--cv-space-2);">
                <button type="submit" class="cv-btn cv-btn--primary">Apply to selected</button>
                <button type="button" class="cv-btn cv-btn--secondary" data-tld-bulk-clear>Clear</button>
            </div>
        </div>
    </form>
    <table class="cv-table" id="tld-pricing-table">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" data-tld-select-all aria-label="Select all TLDs" style="cursor:pointer;"></th>
                <th style="width:70px;">Order</th>
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
                    <td><input type="checkbox" name="ids[]" form="tld-bulk-update-form" value="<?= (int) $price['id'] ?>" data-tld-checkbox aria-label="Select <?= e($price['tld']) ?>" style="cursor:pointer;"></td>
                    <td><input type="number" class="cv-input" form="tld-reorder-form" name="sort_order[<?= (int) $price['id'] ?>]" value="<?= (int) ($price['sort_order'] ?? 0) ?>" style="width:64px;padding:4px 6px;" aria-label="Display order for <?= e($price['tld']) ?>"></td>
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
                                'grace_period_days' => (int) ($price['grace_period_days'] ?? 30),
                                'redemption_period_days' => (int) ($price['redemption_period_days'] ?? 30),
                                'redemption_fee' => number_format((float) ($price['redemption_fee'] ?? 0), 2, '.', ''),
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
                    <td colspan="10" style="text-align:center;color:var(--cv-text-secondary);padding:var(--cv-space-4);">No domain TLD pricing configured yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
// All behaviour for this page lives in public/assets/js/app.js behind
// delegated data-attribute listeners. SecurityHeaders sends script-src
// 'self' with no 'unsafe-inline', so an inline <script> (or an onclick=
// attribute) here is silently blocked by the browser and the buttons simply
// do nothing -- which is exactly why the bulk-add and WHMCS-import buttons
// were dead.
?>

