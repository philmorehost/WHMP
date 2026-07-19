<?php
/** @var array<int, array<string, mixed>> $registrars */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Registrars</h1>
    <p><a href="/admin/domains">&larr; Back to domains</a></p>
</div>

<?php foreach ($registrars as $registrar): ?>
    <?php $config = json_decode((string) ($registrar['config'] ?? '{}'), true) ?: []; ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <strong><?= e($registrar['name']) ?></strong>
            <div style="display:flex;gap:var(--cv-space-2);align-items:center;">
                <?php if ($registrar['is_enabled']): ?>
                    <span class="cv-badge cv-badge--success">Enabled</span>
                <?php else: ?>
                    <span class="cv-badge cv-badge--neutral">Disabled</span>
                <?php endif; ?>
                <form method="post" action="/admin/registrars/<?= e($registrar['slug']) ?>/toggle"><?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit"><?= $registrar['is_enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
            </div>
        </div>

        <?php if ($registrar['slug'] === 'upperlink'): ?>
            <form method="post" action="/admin/registrars/upperlink/config" style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">Reseller Client Email</label>
                    <input class="cv-input" name="email" value="<?= e((string) ($config['email'] ?? '')) ?>">
                </div>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">API Key</label>
                    <input class="cv-input" type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                </div>
                <button class="cv-btn" type="submit">Save</button>
            </form>
        <?php elseif ($registrar['slug'] === 'connectreseller'): ?>
            <form method="post" action="/admin/registrars/connectreseller/config" style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">API Key</label>
                    <input class="cv-input" type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                </div>
                <button class="cv-btn" type="submit">Save</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
