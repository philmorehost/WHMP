<?php
/** @var array<string, mixed> $admin */
/** @var string|null $error */
$enabled = (int) $admin['two_factor_enabled'] === 1;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Account Security</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Two-Factor Authentication</h2>

    <?php if ($enabled): ?>
        <p><span class="cv-badge cv-badge--success">Enabled</span></p>
        <p style="color:var(--cv-text-secondary);">Your account requires a code from your authenticator app (or a recovery code) at every login.</p>
        <form method="post" action="/admin/account/security/disable" data-confirm="Disable two-factor authentication? This makes your account easier to compromise if your password leaks."><?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label">Confirm your password to disable</label>
                <input class="cv-input" type="password" name="password" required>
            </div>
            <button class="cv-btn cv-btn--danger" type="submit">Disable 2FA</button>
        </form>
    <?php else: ?>
        <p><span class="cv-badge cv-badge--neutral">Disabled</span></p>
        <p style="color:var(--cv-text-secondary);">Add a second factor (an authenticator app like Google Authenticator, Authy, or 1Password) so a leaked password alone can't be used to log in.</p>
        <form method="post" action="/admin/account/security/enable"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Enable 2FA</button>
        </form>
    <?php endif; ?>
</div>
