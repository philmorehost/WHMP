<?php
/** @var array<int, array<string, mixed>> $currencies */
/** @var string|null $error */
?>
<style>
.admin-cur-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-cur-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-cur-hero__back {
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
.admin-cur-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-cur-alert {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 8px;
    padding: 12px 16px;
    color: #ef4444;
    margin-bottom: 24px;
    font-size: .9rem;
}
.admin-cur-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-cur-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-cur-card__body {
    padding: 24px;
}
.admin-cur-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-cur-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-cur-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-cur-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-cur-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-cur-input {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
}
.admin-cur-input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-cur-badge--default {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-cur-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-cur-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-cur-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-cur-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-cur-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-cur-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
</style>

<div class="admin-cur-hero">
    <a href="/admin" class="admin-cur-hero__back">← Back to Dashboard</a>
    <h1 class="admin-cur-hero__title">💱 Currencies</h1>
</div>

<?php if ($error !== null): ?>
    <div class="admin-cur-alert">⚠️ <?= e($error) ?></div>
<?php endif; ?>

<div class="admin-cur-card">
    <h2 class="admin-cur-card__title">💱 Active Currencies</h2>
    <div class="admin-cur-card__body" style="padding:0;overflow-x:auto;">
        <div style="overflow-x:auto;">
            <table class="admin-cur-table" id="currencies-table">
                <thead><tr><th>Code</th><th>Symbol</th><th>Exchange Rate</th><th>Status</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($currencies as $currency): ?>
                    <tr>
                        <td>
                            <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>" style="display:contents;"><?= csrf_field() ?>
                                <input class="admin-cur-input" name="code" value="<?= e($currency['code']) ?>" maxlength="3" style="width:5rem;" required>
                        </td>
                        <td><input class="admin-cur-input" name="symbol" value="<?= e($currency['symbol']) ?>" style="width:4rem;" required></td>
                        <td>
                            <?php // A rate reads "units per 1 base unit", so the base currency's own rate can only be 1. The repository enforces it; the input says so rather than silently snapping back. ?>
                            <?php if ((int) $currency['is_default'] === 1): ?>
                                <input class="admin-cur-input" type="number" step="0.0001" name="exchange_rate" value="1.0000" style="width:8rem;background:var(--cv-bg-subtle);" readonly title="The default currency is the unit every other rate is quoted against, so its own rate is always 1.">
                                <div style="font-size:.72rem;color:var(--cv-text-secondary);margin-top:2px;">Base currency — always 1</div>
                            <?php else: ?>
                                <input class="admin-cur-input" type="number" step="0.0001" name="exchange_rate" value="<?= e((string) $currency['exchange_rate']) ?>" style="width:8rem;" required>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $currency['is_default'] === 1): ?>
                                <span class="admin-cur-badge--default">⭐ Default</span>
                            <?php else: ?>
                                <span style="color:var(--cv-text-secondary);font-size:.85rem;">Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="display:flex;gap:6px;align-items:center;">
                            <button class="admin-cur-btn" type="submit">💾 Save</button>
                            </form>
                            <?php if ((int) $currency['is_default'] !== 1): ?>
                                <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>/default" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <button class="admin-cur-btn--secondary" type="submit" style="padding:6px 12px;">⭐ Default</button>
                                </form>
                                <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>/delete" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <button class="admin-cur-btn--danger" type="submit" style="padding:6px 12px;">🗑️ Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($currencies === []): ?>
                    <tr><td colspan="5" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No currencies configured.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// The five lines that used to sit here (</td></tr>, an endforeach, </tbody>
// and </table>) were leftovers from an earlier version of this page. The
// endforeach had no matching foreach, so the whole file failed to parse and
// /admin/currencies was a fatal error. The Add Currency form below is live
// functionality and is kept — it just needed its own card, since the table's
// wrappers already closed above.
?>
<div class="admin-cur-card">
    <h3 class="admin-cur-card__title">➕ Add Currency</h3>
    <form method="post" action="/admin/currencies" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Code</label>
            <input class="cv-input" name="code" placeholder="EUR" maxlength="3" style="width:5rem;" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Symbol</label>
            <input class="cv-input" name="symbol" placeholder="€" style="width:4rem;" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Exchange Rate (vs. base)</label>
            <input class="cv-input" type="number" step="0.0001" name="exchange_rate" placeholder="0.92" style="width:7rem;" required>
        </div>
        <button class="cv-btn" type="submit">Add</button>
    </form>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
        Exchange rates are set manually here — there's no live FX feed wired up, so keep these current by hand.
    </p>
</div>
