<?php
/** @var array<int, array<string, mixed>> $gateways */
/** @var string $baseUrl */
$webhookUrl = static fn (string $slug): string => $baseUrl . '/pay/' . $slug . '/webhook';
?>
<style>
.admin-gw-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-gw-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-gw-hero__content {
    position: relative;
    z-index: 1;
}
.admin-gw-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.admin-gw-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-gw-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-gw-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 20px;
    overflow: hidden;
}
.admin-gw-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    gap: 16px;
    flex-wrap: wrap;
}
.admin-gw-card__title {
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0;
}
.admin-gw-card__actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.admin-gw-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
}
.admin-gw-badge--enabled {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-gw-badge--disabled {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}
.admin-gw-btn {
    background: var(--cv-bg-surface-sunken);
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}
.admin-gw-btn:hover {
    background: var(--cv-bg-surface);
    border-color: var(--cv-color-brand-500);
}
.admin-gw-card__body {
    padding: 24px;
}
.admin-gw-field {
    margin-bottom: 16px;
}
.admin-gw-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.admin-gw-field input,
.admin-gw-field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
    box-sizing: border-box;
}
.admin-gw-field input:focus,
.admin-gw-field textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-gw-field textarea {
    resize: vertical;
    min-height: 100px;
}
.admin-gw-btn-save {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-gw-btn-save:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37,99,235,.3);
}
.admin-gw-hint {
    color: var(--cv-text-secondary);
    font-size: .8rem;
    margin-top: 12px;
    padding: 8px 12px;
    background: var(--cv-bg-surface-sunken);
    border-radius: 6px;
}
.admin-gw-code {
    background: var(--cv-bg-surface-sunken);
    padding: 4px 8px;
    border-radius: 4px;
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .8rem;
    word-break: break-all;
}
.admin-gw-copy-btn {
    background: var(--cv-bg-surface-sunken);
    border: 1px solid var(--cv-border-default);
    border-radius: 4px;
    padding: 4px 8px;
    font-size: .7rem;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-gw-copy-btn:hover {
    background: var(--cv-bg-surface);
    border-color: var(--cv-color-brand-500);
}
@media (max-width: 768px) {
    .admin-gw-hero {
        padding: 32px 24px;
    }
    .admin-gw-hero__title {
        font-size: 1.5rem;
    }
    .admin-gw-card__header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-gw-hero">
    <div class="admin-gw-hero__content">
        <a href="/admin" class="admin-gw-hero__back">
            <span>←</span>
            <span>Back to Dashboard</span>
        </a>
        <h1 class="admin-gw-hero__title">💳 Payment Gateways</h1>
    </div>
</div>

<?php foreach ($gateways as $gateway): ?>
    <?php $config = json_decode((string) ($gateway['config'] ?? '{}'), true) ?: []; ?>
    <div class="admin-gw-card">
        <div class="admin-gw-card__header">
            <h3 class="admin-gw-card__title"><?= e($gateway['name']) ?></h3>
            <div class="admin-gw-card__actions">
                <?php if ($gateway['is_enabled']): ?>
                    <span class="admin-gw-badge admin-gw-badge--enabled">Enabled</span>
                <?php else: ?>
                    <span class="admin-gw-badge admin-gw-badge--disabled">Disabled</span>
                <?php endif; ?>
                <form method="post" action="/admin/gateways/<?= e($gateway['slug']) ?>/toggle" style="margin:0;"><?= csrf_field() ?>
                    <button class="admin-gw-btn" type="submit"><?= $gateway['is_enabled'] ? '🔌 Disable' : '✓ Enable' ?></button>
                </form>
            </div>
        </div>
        <div class="admin-gw-card__body">

        <?php if ($gateway['slug'] === 'manual'): ?>
            <form method="post" action="/admin/gateways/manual/config"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label>Bank Transfer Instructions (shown to clients on unpaid invoices)</label>
                    <textarea name="bank_details"><?= e((string) ($config['bank_details'] ?? '')) ?></textarea>
                </div>
                <button class="admin-gw-btn-save" type="submit">💾 Save Instructions</button>
            </form>
        <?php elseif ($gateway['slug'] === 'paystack'): ?>
            <form method="post" action="/admin/gateways/paystack/config"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label>Secret Key</label>
                    <input type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'sk_...' ?>">
                </div>
                <div class="admin-gw-field">
                    <label>Public Key</label>
                    <input name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="pk_...">
                </div>
                <div class="admin-gw-field">
                    <label>Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="admin-gw-btn-save" type="submit">💾 Save Paystack Config</button>
            </form>
            <div class="admin-gw-hint">
                <strong>Webhook URL:</strong> <span class="admin-gw-code" id="webhook-paystack"><?= e($webhookUrl('paystack')) ?></span>
                <button type="button" class="admin-gw-copy-btn" data-copy-target="#webhook-paystack">📋 Copy</button>
                — Add this in your Paystack dashboard.
            </div>
        <?php elseif ($gateway['slug'] === 'flutterwave'): ?>
            <form method="post" action="/admin/gateways/flutterwave/config"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label>Secret Key</label>
                    <input type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'FLWSECK_...' ?>">
                </div>
                <div class="admin-gw-field">
                    <label>Public Key</label>
                    <input name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="FLWPUBK_...">
                </div>
                <div class="admin-gw-field">
                    <label>Webhook Secret Hash</label>
                    <input type="password" name="secret_hash" placeholder="<?= !empty($config['secret_hash']) ? '••••••••  (leave blank to keep)' : 'set in Flutterwave dashboard' ?>">
                </div>
                <div class="admin-gw-field">
                    <label>Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="admin-gw-btn-save" type="submit">💾 Save Flutterwave Config</button>
            </form>
            <div class="admin-gw-hint">
                <strong>Webhook URL:</strong> <span class="admin-gw-code" id="webhook-flutterwave"><?= e($webhookUrl('flutterwave')) ?></span>
                <button type="button" class="admin-gw-copy-btn" data-copy-target="#webhook-flutterwave">📋 Copy</button>
                — Add in your Flutterwave dashboard with the same secret hash.
            </div>
        <?php elseif ($gateway['slug'] === 'payhub'): ?>
            <form method="post" action="/admin/gateways/payhub/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label style="">Secret Key</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'sk_live_...' ?>">
                </div>
                <div class="admin-gw-field">
                    <label style="">Public Key</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="pk_live_...">
                </div>
                <div class="admin-gw-field">
                    <label style="">Webhook Secret Hash</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" type="password" name="secret_hash" placeholder="<?= !empty($config['secret_hash']) ? '••••••••  (leave blank to keep)' : 'Webhook signature key' ?>">
                </div>
                <div class="admin-gw-field">
                    <label style="">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="admin-gw-btn-save" type="submit">Save Payhub Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-payhub"><?= e($webhookUrl('payhub')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-payhub">Copy</button>
                — add this in your Payhub dashboard.
            </p>
        <?php elseif ($gateway['slug'] === 'plisio'): ?>
            <form method="post" action="/admin/gateways/plisio/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label style="">API Secret Key</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" type="password" name="api_key" placeholder="<?= !empty($config['api_key']) ? '••••••••  (leave blank to keep)' : 'plisio_api_key' ?>">
                </div>
                <div class="admin-gw-field">
                    <label style="">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'USD')) ?>" placeholder="USD">
                </div>
                <button class="admin-gw-btn-save" type="submit">Save Plisio Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-plisio"><?= e($webhookUrl('plisio')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-plisio">Copy</button>
                — add this in your Plisio dashboard.
            </p>
        <?php elseif ($gateway['slug'] === 'paypal'): ?>
            <form method="post" action="/admin/gateways/paypal/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="admin-gw-field">
                    <label style="">Client ID</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="client_id" value="<?= e((string) ($config['client_id'] ?? '')) ?>" placeholder="AeA1QIZX...">
                </div>
                <div class="admin-gw-field">
                    <label style="">Client Secret</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" type="password" name="client_secret" placeholder="<?= !empty($config['client_secret']) ? '••••••••  (leave blank to keep)' : 'EOFHW...' ?>">
                </div>
                <div class="admin-gw-field">
                    <label style="">Webhook ID (from your PayPal app's Webhooks page)</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="webhook_id" value="<?= e((string) ($config['webhook_id'] ?? '')) ?>" placeholder="8PT597110X687430LKGECATA">
                </div>
                <div class="admin-gw-field">
                    <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                        <input type="checkbox" name="sandbox" value="1" <?= !empty($config['sandbox']) ? 'checked' : '' ?>>
                        Use PayPal Sandbox (testing)
                    </label>
                </div>
                <div class="admin-gw-field">
                    <label style="">Gateway Currency (e.g. USD, EUR, GBP)</label>
                    <input style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary); font-size:.9rem;" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'USD')) ?>" placeholder="USD">
                </div>
                <button class="admin-gw-btn-save" type="submit">Save PayPal Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-paypal"><?= e($webhookUrl('paypal')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-paypal">Copy</button>
                — add this in your PayPal app's Webhooks page, subscribed to PAYMENT.CAPTURE.COMPLETED and CHECKOUT.ORDER.APPROVED.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<!-- Gateway Execution Logs Card -->
<div class="admin-gw-card" style="margin-top: 32px;">
    <div class="admin-gw-card__header">
        <h3 class="admin-gw-card__title">📜 Live Payment Gateway Execution Logs</h3>
        <span style="font-size: .8rem; color: var(--cv-text-secondary);">Recorded automatically whenever a client clicks any payment button</span>
    </div>
    <div style="padding: 20px 24px;">
        <?php if (empty($gatewayLogs)): ?>
            <p style="color: var(--cv-text-secondary); margin: 0; font-size: .85rem;">No payment gateway execution logs recorded yet. Logs will automatically appear here when clients click any payment button on invoices.</p>
        <?php else: ?>
            <div style="background: #0f172a; color: #38bdf8; font-family: monospace; font-size: .8rem; padding: 16px; border-radius: 8px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; line-height: 1.5;">
                <?php foreach ($gatewayLogs as $logEntry): ?>
                    <?= e($logEntry) . "\n" ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
