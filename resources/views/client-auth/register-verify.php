<?php
/** @var string $email */
/** @var string|null $error */
/** @var bool $resent */
?>
<div class="cv-card" style="max-width:26rem;margin:var(--cv-space-8) auto;box-sizing:border-box;">
    <h1 class="cv-card__title">Verify Your Email</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        We sent a 6-digit code to <strong><?= e($email) ?></strong>. Enter it below to finish creating your account.
    </p>

    <?php if ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($resent): ?>
        <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">A new code has been sent.</div>
    <?php endif; ?>

    <form method="post" action="/client/register/verify" style="display:flex;flex-direction:column;gap:var(--cv-space-3);width:100%;box-sizing:border-box;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Verification Code</label>
            <input class="cv-input" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required
                   style="width:100%;box-sizing:border-box;font-size:1.5rem;letter-spacing:6px;text-align:center;">
        </div>
        <button class="cv-btn" type="submit" style="width:100%;box-sizing:border-box;">Verify &amp; Create Account</button>
    </form>

    <form method="post" action="/client/register/resend-otp" style="margin-top:var(--cv-space-3);">
        <?= csrf_field() ?>
        <button class="cv-btn cv-btn--secondary" type="submit" style="width:100%;box-sizing:border-box;">Resend Code</button>
    </form>

    <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);"><a href="/client/register">&larr; Back to registration</a></p>
</div>
