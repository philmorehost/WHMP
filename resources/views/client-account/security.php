<?php
/** @var array<string, mixed> $client */
/** @var string|null $error */
/** @var array<int, array{slug: string, metadata: array{name: string, description: string}, active: bool}> $securityQuestionOptions */
/** @var array{slug: string, question: string}|null $currentSecurityQuestion */
$enabled = (int) $client['two_factor_enabled'] === 1;
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">Two-Factor Authentication</h1>
    <p><a href="/client/account">&larr; Back to my account</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <?php if ($enabled): ?>
        <p><span class="cv-badge cv-badge--success">Enabled</span></p>
        <p style="color:var(--cv-text-secondary);">Your account requires a code from your authenticator app (or a recovery code) at every login.</p>
        <form method="post" action="/client/account/security/disable" data-confirm="Disable two-factor authentication? This makes your account easier to compromise if your password leaks."><?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label">Confirm your password to disable</label>
                <input class="cv-input" type="password" name="password" required>
            </div>
            <button class="cv-btn cv-btn--danger" type="submit">Disable 2FA</button>
        </form>
    <?php else: ?>
        <p><span class="cv-badge cv-badge--neutral">Disabled</span></p>
        <p style="color:var(--cv-text-secondary);">Add a second factor (an authenticator app like Google Authenticator, Authy, or 1Password) so a leaked password alone can't be used to log in.</p>
        <form method="post" action="/client/account/security/enable"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Enable 2FA</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-4) auto 0;">
    <h2 class="cv-card__title">Security Question</h2>
    <p style="color:var(--cv-text-secondary);">An extra identity-verification step for password reset — used in addition to, not instead of, your reset email.</p>

    <?php if ($currentSecurityQuestion !== null): ?>
        <p><span class="cv-badge cv-badge--success">Configured</span></p>
        <p style="color:var(--cv-text-secondary);">Question: <strong><?= e($currentSecurityQuestion['question']) ?></strong></p>
        <form method="post" action="/client/account/security-question/clear" data-confirm="Remove your security question? Password reset will no longer require it."><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit">Remove</button>
        </form>
    <?php elseif ($securityQuestionOptions === []): ?>
        <p><span class="cv-badge cv-badge--neutral">Not available</span></p>
        <p style="color:var(--cv-text-secondary);">No security questions have been activated yet.</p>
    <?php else: ?>
        <p><span class="cv-badge cv-badge--neutral">Not configured</span></p>
        <form method="post" action="/client/account/security-question"><?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label">Question</label>
                <select class="cv-input" name="slug" required>
                    <?php foreach ($securityQuestionOptions as $option): ?>
                        <option value="<?= e($option['slug']) ?>"><?= e($option['metadata']['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cv-field">
                <label class="cv-label">Answer</label>
                <input class="cv-input" type="text" name="answer" required>
            </div>
            <button class="cv-btn" type="submit">Save</button>
        </form>
    <?php endif; ?>
</div>
