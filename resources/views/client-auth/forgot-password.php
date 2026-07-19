<?php
/** @var bool $sent */
?>
<div class="cv-card" style="max-width:26rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">Forgot Password</h1>

    <?php if ($sent): ?>
        <div class="cv-field-success" style="margin-bottom:var(--cv-space-3);">If that email is registered, we've sent a password reset link to it. The link expires in 60 minutes.</div>
        <p><a href="/client/login">&larr; Back to login</a></p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);">Enter your account email and we'll send you a link to reset your password.</p>
        <form method="post" action="/client/forgot-password"><?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label">Email</label>
                <input class="cv-input" type="email" name="email" autofocus required>
            </div>
            <button class="cv-btn" type="submit">Send Reset Link</button>
        </form>
        <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);"><a href="/client/login">&larr; Back to login</a></p>
    <?php endif; ?>
</div>
