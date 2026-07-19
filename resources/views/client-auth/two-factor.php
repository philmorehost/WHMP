<?php
/** @var string|null $error */
?>
<div class="cv-card" style="max-width:28rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">Two-Factor Verification</h1>
    <p style="color:var(--cv-text-secondary);">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

    <?php if ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/client/login/2fa"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Code</label>
            <input class="cv-input" name="code" autofocus autocomplete="one-time-code" inputmode="numeric">
        </div>
        <button class="cv-btn" type="submit">Verify</button>
    </form>
    <p><a href="/client/login">&larr; Back to login</a></p>
</div>
