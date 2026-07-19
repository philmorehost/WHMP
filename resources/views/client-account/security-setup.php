<?php
/** @var string $secret */
/** @var string $provisioningUri */
/** @var array<int, string>|null $recoveryCodes */
/** @var string|null $error */
$formattedSecret = implode(' ', str_split($secret, 4));
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">Set Up Two-Factor Authentication</h1>
    <p><a href="/client/account/security">&larr; Back to security</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">1. Add to your authenticator app</h2>
    <p style="color:var(--cv-text-secondary);">Add a new account in your authenticator app (Google Authenticator, Authy, 1Password, ...) using this key:</p>
    <p style="font-family:monospace;font-size:var(--cv-text-lg);letter-spacing:0.1em;background:var(--cv-color-brand-50);padding:var(--cv-space-3);border-radius:var(--cv-radius-sm);"><?= e($formattedSecret) ?></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Or, if your app supports importing a link directly: <code style="word-break:break-all;"><?= e($provisioningUri) ?></code></p>
</div>

<?php if ($recoveryCodes !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">2. Save your recovery codes</h2>
        <p style="color:var(--cv-text-secondary);">Each code works once, if you lose access to your authenticator app. <strong>This is the only time these will be shown.</strong></p>
        <div style="font-family:monospace;background:var(--cv-color-brand-50);padding:var(--cv-space-3);border-radius:var(--cv-radius-sm);display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-2);">
            <?php foreach ($recoveryCodes as $code): ?>
                <div><?= e($code) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">3. Confirm it works</h2>
    <form method="post" action="/client/account/security/confirm"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Enter the current code from your app</label>
            <input class="cv-input" name="code" autofocus autocomplete="one-time-code" inputmode="numeric">
        </div>
        <button class="cv-btn" type="submit">Confirm &amp; Enable</button>
    </form>
</div>
