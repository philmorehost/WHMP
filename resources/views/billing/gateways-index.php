<?php
/** @var array<int, array<string, mixed>> $gateways */
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
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
                Webhook URL: <code>/pay/paystack/webhook</code> — add this in your Paystack dashboard.
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
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
                Webhook URL: <code>/pay/flutterwave/webhook</code> — add this in your Flutterwave dashboard, with the same secret hash configured here.
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
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
                Webhook URL: <code>/pay/payhub/webhook</code> — add this in your Payhub dashboard.
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
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
                Webhook URL: <code>/pay/plisio/webhook</code> — add this in your Plisio dashboard.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
