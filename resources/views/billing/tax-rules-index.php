<?php
/** @var array<int, array<string, mixed>> $rules */
/** @var ?string $sellerCountryCode */
?>
<style>
.admin-tr-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-tr-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-tr-hero__back {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
}
.admin-tr-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-tr-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-tr-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-tr-card__body {
    padding: 24px;
}
.admin-tr-card__desc {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    margin-bottom: 16px;
}
.admin-tr-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}
.admin-tr-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
}
.admin-tr-field input {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
}
.admin-tr-field input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-tr-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-tr-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-tr-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
    padding: 6px 12px;
    font-size: .75rem;
}
.admin-tr-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-tr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-tr-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-tr-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-tr-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-tr-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-tr-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    align-items: end;
}
</style>

<div class="admin-tr-hero">
    <a href="/admin" class="admin-tr-hero__back">← Back to Dashboard</a>
    <h1 class="admin-tr-hero__title">💰 Tax Rules</h1>
</div>

<div class="admin-tr-card">
    <h2 class="admin-tr-card__title">🌍 Seller Country (VAT Reverse Charge)</h2>
    <div class="admin-tr-card__body">
        <p class="admin-tr-card__desc">
            This business's own country. Required for reverse-charge: a client in a <em>different</em> country with a structurally valid VAT number is zero-rated instead of charged this business's tax rules. Leave blank to disable reverse-charge entirely.
        </p>
        <form method="post" action="/admin/tax-rules/seller-country" style="display:flex;gap:12px;align-items:end;"><?= csrf_field() ?>
            <div class="admin-tr-field" style="margin-bottom:0;">
                <label>Seller Country Code</label>
                <input name="seller_country_code" placeholder="NG" maxlength="2" value="<?= e((string) ($sellerCountryCode ?? '')) ?>" style="width:6rem;">
            </div>
            <button class="admin-tr-btn" type="submit">💾 Save</button>
        </form>
    </div>
</div>

<div class="admin-tr-card">
    <div style="padding:24px;border-bottom:1px solid var(--cv-border-default);">
        <h2 style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.25rem;color:var(--cv-text-primary);margin:0;">📋 Rules</h2>
    </div>
    <div style="overflow-x:auto;">
        <table class="admin-tr-table" id="tax-rules-table">
            <thead><tr><th>Country</th><th>State</th><th>Name</th><th style="text-align:right;">Rate</th><th style="width:80px;"></th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><code style="background:var(--cv-bg-surface-sunken);padding:2px 6px;border-radius:4px;"><?= e($rule['country_code']) ?></code></td>
                    <td><?= e((string) ($rule['state'] ?? 'Whole country')) ?></td>
                    <td><?= e($rule['name']) ?></td>
                    <td style="text-align:right;font-weight:700;"><?= number_format((float) $rule['rate'], 2) ?>%</td>
                    <td>
                        <form method="post" action="/admin/tax-rules/<?= (int) $rule['id'] ?>/delete" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="admin-tr-btn--danger" type="submit">🗑️ Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rules === []): ?>
                <tr><td colspan="5" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No tax rules yet — every client is untaxed by default.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-tr-card">
    <h2 class="admin-tr-card__title">➕ Add / Update Rule</h2>
    <div class="admin-tr-card__body">
        <form method="post" action="/admin/tax-rules" class="admin-tr-form"><?= csrf_field() ?>
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
