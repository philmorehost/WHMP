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

    <h2 class="cv-card__title">Add or Update TLD</h2>
    <form method="post" action="/admin/domain-pricing" style="display:grid;grid-template-columns:repeat(5, 1fr);gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">TLD (e.g. .com)</label>
            <input class="cv-input" name="tld" placeholder=".com" required>
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
        <button class="cv-btn" type="submit" style="grid-column:span 5;margin-top:var(--cv-space-2);">Save TLD Pricing</button>
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
                <th>Registrar</th>
                <th>Register Price</th>
                <th>Transfer Price</th>
                <th>Renewal Price</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pricingList as $price): ?>
                <tr>
                    <td><strong><?= e($price['tld']) ?></strong></td>
                    <td><span class="cv-badge cv-badge--neutral"><?= e($price['registrar_slug']) ?></span></td>
                    <td>$<?= number_format((float) $price['register_price'], 2) ?></td>
                    <td>$<?= number_format((float) $price['transfer_price'], 2) ?></td>
                    <td>$<?= number_format((float) $price['renew_price'], 2) ?></td>
                    <td>
                        <form method="post" action="/admin/domain-pricing/<?= (int) $price['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit" onclick="return confirm('Are you sure you want to delete this TLD?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($pricingList === []): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:var(--cv-text-secondary);padding:var(--cv-space-4);">No domain TLD pricing configured yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
