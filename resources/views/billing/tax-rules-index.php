<?php
/** @var array<int, array<string, mixed>> $rules */
/** @var ?string $sellerCountryCode */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Tax Rules</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h3 class="cv-card__title" style="font-size:var(--cv-text-md);">Seller Country (VAT Reverse Charge)</h3>
    <p style="color:var(--cv-text-secondary);">This business's own country. Required for reverse-charge: a client in a <em>different</em> country with a structurally valid VAT number is zero-rated instead of charged this business's tax rules. Leave blank to disable reverse-charge entirely.</p>
    <form method="post" action="/admin/tax-rules/seller-country" style="display:flex;gap:var(--cv-space-2);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Seller Country Code</label>
            <input class="cv-input" name="seller_country_code" placeholder="NG" maxlength="2" value="<?= e((string) ($sellerCountryCode ?? '')) ?>" style="width:6rem;">
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#tax-rules-table', 'placeholder' => 'Search tax rules...']) ?>
    </div>
    <table class="cv-table" id="tax-rules-table">
        <thead><tr><th>Country</th><th>State</th><th>Name</th><th>Rate</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $rule): ?>
            <tr>
                <td><?= e($rule['country_code']) ?></td>
                <td><?= e((string) ($rule['state'] ?? 'Whole country')) ?></td>
                <td><?= e($rule['name']) ?></td>
                <td><?= number_format((float) $rule['rate'], 2) ?>%</td>
                <td>
                    <form method="post" action="/admin/tax-rules/<?= (int) $rule['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rules === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No tax rules yet — every client is untaxed by default.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add / Update Rule</h3>
    <form method="post" action="/admin/tax-rules" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Country Code</label>
            <input class="cv-input" name="country_code" placeholder="NG" maxlength="2" required style="width:6rem;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">State (blank = whole country)</label>
            <input class="cv-input" name="state">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" value="VAT">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Rate %</label>
            <input class="cv-input" type="number" step="0.01" name="rate" style="width:6rem;">
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>
</div>
