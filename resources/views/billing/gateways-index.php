<?php
/** @var array<int, array<string, mixed>> $gateways */
/** @var string $baseUrl */
$webhookUrl = static fn (string $slug): string => $baseUrl . '/pay/' . $slug . '/webhook';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Payment Gateways</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php foreach ($gateways as $gateway): ?>
    <?php $config = json_decode((string) ($gateway['config'] ?? '{}'), true) ?: []; ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <strong><?= e($gateway['name']) ?></strong>
            <div style="display:flex;gap:var(--cv-space-2);align-items:center;">
                <?php if ($gateway['is_enabled']): ?>
                    <span class="cv-badge cv-badge--success">Enabled</span>
                <?php else: ?>
                    <span class="cv-badge cv-badge--neutral">Disabled</span>
                <?php endif; ?>
                <form method="post" action="/admin/gateways/<?= e($gateway['slug']) ?>/toggle"><?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit"><?= $gateway['is_enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
            </div>
        </div>

        <?php if ($gateway['slug'] === 'manual'): ?>
            <form method="post" action="/admin/gateways/manual/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Bank Transfer Instructions (shown to clients on unpaid invoices)</label>
                    <textarea class="cv-textarea" name="bank_details" rows="3"><?= e((string) ($config['bank_details'] ?? '')) ?></textarea>
                </div>
                <button class="cv-btn" type="submit">Save Instructions</button>
            </form>
        <?php elseif ($gateway['slug'] === 'paystack'): ?>
            <form method="post" action="/admin/gateways/paystack/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Secret Key</label>
                    <input class="cv-input" type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'sk_...' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Public Key</label>
                    <input class="cv-input" name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="pk_...">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input class="cv-input" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="cv-btn" type="submit">Save Paystack Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-paystack"><?= e($webhookUrl('paystack')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-paystack">Copy</button>
                — add this in your Paystack dashboard.
            </p>
        <?php elseif ($gateway['slug'] === 'flutterwave'): ?>
            <form method="post" action="/admin/gateways/flutterwave/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Secret Key</label>
                    <input class="cv-input" type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'FLWSECK_...' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Public Key</label>
                    <input class="cv-input" name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="FLWPUBK_...">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Webhook Secret Hash</label>
                    <input class="cv-input" type="password" name="secret_hash" placeholder="<?= !empty($config['secret_hash']) ? '••••••••  (leave blank to keep)' : 'set in Flutterwave dashboard' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input class="cv-input" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="cv-btn" type="submit">Save Flutterwave Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-flutterwave"><?= e($webhookUrl('flutterwave')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-flutterwave">Copy</button>
                — add this in your Flutterwave dashboard, with the same secret hash configured here.
            </p>
        <?php elseif ($gateway['slug'] === 'payhub'): ?>
            <form method="post" action="/admin/gateways/payhub/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Secret Key</label>
                    <input class="cv-input" type="password" name="secret_key" placeholder="<?= !empty($config['secret_key']) ? '••••••••  (leave blank to keep)' : 'sk_live_...' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Public Key</label>
                    <input class="cv-input" name="public_key" value="<?= e((string) ($config['public_key'] ?? '')) ?>" placeholder="pk_live_...">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Webhook Secret Hash</label>
                    <input class="cv-input" type="password" name="secret_hash" placeholder="<?= !empty($config['secret_hash']) ? '••••••••  (leave blank to keep)' : 'Webhook signature key' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input class="cv-input" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'NGN')) ?>" placeholder="NGN">
                </div>
                <button class="cv-btn" type="submit">Save Payhub Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-payhub"><?= e($webhookUrl('payhub')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-payhub">Copy</button>
                — add this in your Payhub dashboard.
            </p>
        <?php elseif ($gateway['slug'] === 'plisio'): ?>
            <form method="post" action="/admin/gateways/plisio/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">API Secret Key</label>
                    <input class="cv-input" type="password" name="api_key" placeholder="<?= !empty($config['api_key']) ? '••••••••  (leave blank to keep)' : 'plisio_api_key' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Gateway Currency (e.g. NGN, USD, EUR)</label>
                    <input class="cv-input" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'USD')) ?>" placeholder="USD">
                </div>
                <button class="cv-btn" type="submit">Save Plisio Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-plisio"><?= e($webhookUrl('plisio')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-plisio">Copy</button>
                — add this in your Plisio dashboard.
            </p>
        <?php elseif ($gateway['slug'] === 'paypal'): ?>
            <form method="post" action="/admin/gateways/paypal/config" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Client ID</label>
                    <input class="cv-input" name="client_id" value="<?= e((string) ($config['client_id'] ?? '')) ?>" placeholder="AeA1QIZX...">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Client Secret</label>
                    <input class="cv-input" type="password" name="client_secret" placeholder="<?= !empty($config['client_secret']) ? '••••••••  (leave blank to keep)' : 'EOFHW...' ?>">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Webhook ID (from your PayPal app's Webhooks page)</label>
                    <input class="cv-input" name="webhook_id" value="<?= e((string) ($config['webhook_id'] ?? '')) ?>" placeholder="8PT597110X687430LKGECATA">
                </div>
                <div class="cv-field">
                    <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                        <input type="checkbox" name="sandbox" value="1" <?= !empty($config['sandbox']) ? 'checked' : '' ?>>
                        Use PayPal Sandbox (testing)
                    </label>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Gateway Currency (e.g. USD, EUR, GBP)</label>
                    <input class="cv-input" name="gateway_currency" value="<?= e((string) ($config['gateway_currency'] ?? 'USD')) ?>" placeholder="USD">
                </div>
                <button class="cv-btn" type="submit">Save PayPal Config</button>
            </form>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);display:flex;align-items:center;gap:var(--cv-space-2);">
                Webhook URL: <code id="webhook-paypal"><?= e($webhookUrl('paypal')) ?></code>
                <button type="button" class="cv-btn cv-btn--secondary" style="padding:2px var(--cv-space-2);font-size:var(--cv-text-xs);" data-copy-target="#webhook-paypal">Copy</button>
                — add this in your PayPal app's Webhooks page, subscribed to PAYMENT.CAPTURE.COMPLETED and CHECKOUT.ORDER.APPROVED.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
