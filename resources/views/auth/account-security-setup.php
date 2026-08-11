<?php
/** @var string $secret */
/** @var string $provisioningUri */
/** @var string $qrSvg SVG markup for the provisioning URI */
/** @var array<int, string>|null $recoveryCodes */
/** @var string|null $error */
$formattedSecret = implode(' ', str_split($secret, 4));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Set Up Two-Factor Authentication</h1>
    <p><a href="/admin/account/security">&larr; Back to security</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">1. Scan with your authenticator app</h2>
    <p style="color:var(--cv-text-secondary);">Open Google Authenticator, Authy, 1Password or another TOTP app and scan this code — it contains everything the app needs, so no manual entry is required:</p>
    <div style="text-align:center;margin:var(--cv-space-3) 0;background:var(--cv-bg-surface-sunken, #f8fafc);border:1px solid var(--cv-border-default);border-radius:var(--cv-radius-md);padding:var(--cv-space-4);display:inline-block;width:100%;">
        <img src="data:image/svg+xml;base64,<?= e(base64_encode($qrSvg)) ?>" alt="Two-factor authentication QR code" style="width:min(100%, 220px);height:auto;display:block;margin:0 auto;">
    </div>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">If scanning doesn't work, enter this key manually:</p>
    <p style="font-family:monospace;font-size:var(--cv-text-lg);letter-spacing:0.1em;background:var(--cv-color-brand-50);padding:var(--cv-space-3);border-radius:var(--cv-radius-sm);margin-bottom:0;"><?= e($formattedSecret) ?></p>
</div>

<?php if ($recoveryCodes !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">2. Save your recovery codes</h2>
        <p style="color:var(--cv-text-secondary);">Each code works once, if you lose access to your authenticator app. <strong>This is the only time these will be shown.</strong></p>
        <div style="font-family:monospace;background:var(--cv-color-brand-50);padding:var(--cv-space-3);border-radius:var(--cv-radius-sm);display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-2);">
            <?php foreach ($recoveryCodes as $code): ?>
                <div><?= e($code) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">3. Confirm it works</h2>
    <form method="post" action="/admin/account/security/confirm"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Enter the current code from your app</label>
            <input class="cv-input" name="code" autofocus autocomplete="one-time-code" inputmode="numeric">
        </div>
        <button class="cv-btn" type="submit">Confirm &amp; Enable</button>
    </form>
</div>
