<?php
/** @var array<int, array<string, mixed>> $currencies */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Currencies</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#currencies-table', 'placeholder' => 'Search currencies...']) ?>
    </div>
    <table class="cv-table" id="currencies-table">
        <thead><tr><th>Code</th><th>Symbol</th><th>Exchange Rate</th><th>Default</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($currencies as $currency): ?>
            <tr>
                <td>
                    <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>" style="display:flex;gap:var(--cv-space-2);align-items:center;"><?= csrf_field() ?>
                        <input class="cv-input" name="code" value="<?= e($currency['code']) ?>" maxlength="3" style="width:5rem;" required>
                </td>
                <td><input class="cv-input" name="symbol" value="<?= e($currency['symbol']) ?>" style="width:4rem;" required></td>
                <td>
                    <input class="cv-input" type="number" step="0.0001" name="exchange_rate" value="<?= e((string) $currency['exchange_rate']) ?>" style="width:7rem;" required>
                </td>
                <td>
                    <?php if ((int) $currency['is_default'] === 1): ?>
                        <span class="cv-badge cv-badge--success">Default</span>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">—</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <button class="cv-btn cv-btn--secondary" type="submit">Save</button>
                    </form>
                    <?php if ((int) $currency['is_default'] !== 1): ?>
                        <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>/default"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Make Default</button>
                        </form>
                        <form method="post" action="/admin/currencies/<?= (int) $currency['id'] ?>/delete"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Add Currency</h3>
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
