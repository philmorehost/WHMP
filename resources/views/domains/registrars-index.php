<?php
/** @var array<int, array<string, mixed>> $registrars */
?>
<style>
.admin-reg-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-reg-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-reg-hero__back {
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
.admin-reg-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-reg-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-reg-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-reg-card__title {
    font-weight: 800;
    font-size: 1.1rem;
    color: var(--cv-text-primary);
    margin: 0;
}
.admin-reg-card__body {
    padding: 24px;
}
.admin-reg-badge--enabled {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-reg-badge--disabled {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-reg-btn {
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
.admin-reg-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-reg-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-reg-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-reg-field {
    margin-bottom: 16px;
}
.admin-reg-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    margin-bottom: 6px;
}
.admin-reg-field input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    box-sizing: border-box;
}
.admin-reg-field input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-reg-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: end;
}
</style>

<div class="admin-reg-hero">
    <a href="/admin/domains" class="admin-reg-hero__back">← Back to Domains</a>
    <h1 class="admin-reg-hero__title">🌐 Registrars</h1>
</div>

<?php foreach ($registrars as $registrar): ?>
    <?php $config = json_decode((string) ($registrar['config'] ?? '{}'), true) ?: []; ?>
    <div class="admin-reg-card">
        <div class="admin-reg-card__header">
            <h2 class="admin-reg-card__title"><?= e($registrar['name']) ?></h2>
            <div style="display:flex;gap:12px;align-items:center;">
                <?php if ($registrar['is_enabled']): ?>
                    <span class="admin-reg-badge--enabled">✓ Enabled</span>
                <?php else: ?>
                    <span class="admin-reg-badge--disabled">⊘ Disabled</span>
                <?php endif; ?>
                <form method="post" action="/admin/registrars/<?= e($registrar['slug']) ?>/toggle" style="margin:0;">
                    <?= csrf_field() ?>
                    <button class="admin-reg-btn--secondary" style="padding:8px 16px;" type="submit"><?= $registrar['is_enabled'] ? '⊘ Disable' : '✓ Enable' ?></button>
                </form>
            </div>
        </div>

        <div class="admin-reg-card__body">
            <?php if ($registrar['slug'] === 'upperlink'): ?>
                <form method="post" action="/admin/registrars/upperlink/config" class="admin-reg-form"><?= csrf_field() ?>
                    <div class="admin-reg-field">
                        <label>Reseller Client Email</label>
                        <input name="email" value="<?= e((string) ($config['email'] ?? '')) ?>">
                    </div>
                    <div class="admin-reg-field">
                        <label>API Key</label>
                        <input type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                    </div>
                    <button class="admin-reg-btn" type="submit">💾 Save</button>
                </form>
            <?php elseif ($registrar['slug'] === 'connectreseller'): ?>
                <form method="post" action="/admin/registrars/connectreseller/config" class="admin-reg-form"><?= csrf_field() ?>
                    <div class="admin-reg-field">
                        <label>API Key</label>
                        <input type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                    </div>
                    <button class="admin-reg-btn" type="submit">💾 Save</button>
                </form>
            <?php elseif ($registrar['slug'] === 'resellerclub'): ?>
                <form method="post" action="/admin/registrars/resellerclub/config" class="admin-reg-form"><?= csrf_field() ?>
                    <div class="admin-reg-field">
                        <label>Reseller ID (auth-userid)</label>
                        <input name="reseller_id" value="<?= e((string) ($config['reseller_id'] ?? '')) ?>">
                    </div>
                    <div class="admin-reg-field">
                        <label>API Key</label>
                        <input type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                    </div>
                    <div class="admin-reg-field">
                        <label>Default Customer ID</label>
                        <input name="customer_id" value="<?= e((string) ($config['customer_id'] ?? '')) ?>">
                    </div>
                    <button class="admin-reg-btn" type="submit">💾 Save</button>
                </form>
            </form>
        <?php elseif ($registrar['slug'] === 'namecheap'): ?>
            <form method="post" action="/admin/registrars/namecheap/config" style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">API User</label>
                    <input class="cv-input" name="api_user" value="<?= e((string) ($config['api_user'] ?? '')) ?>">
                </div>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">API Key</label>
                    <input class="cv-input" type="password" name="api_key" value="<?= e((string) ($config['api_key'] ?? '')) ?>">
                </div>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">Username</label>
                    <input class="cv-input" name="username" value="<?= e((string) ($config['username'] ?? '')) ?>">
                </div>
                <div class="cv-field" style="margin-bottom:0;">
                    <label class="cv-label">Whitelisted Client IP</label>
                    <input class="cv-input" name="client_ip" value="<?= e((string) ($config['client_ip'] ?? '')) ?>" placeholder="Set in Namecheap's API settings">
                </div>
                <div class="cv-field" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                        <input type="checkbox" name="sandbox" value="1" <?= !empty($config['sandbox']) ? 'checked' : '' ?>>
                        Sandbox
                    </label>
                </div>
                <button class="cv-btn" type="submit">Save</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
