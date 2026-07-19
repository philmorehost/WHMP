<?php
/** @var string|null $error */
/** @var bool $resetSuccess */
$resetSuccess ??= false;
?>
<div class="cv-card">
    <h1 class="cv-card__title">Admin Login</h1>

    <?php if ($resetSuccess): ?>
        <div class="cv-field-success" style="margin-bottom:var(--cv-space-3);">Your password has been reset. You can now log in.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Username</label>
            <input class="cv-input" name="username" autofocus>
        </div>
        <div class="cv-field">
            <label class="cv-label">Password</label>
            <input class="cv-input" type="password" name="password">
        </div>
        <button class="cv-btn" type="submit">Log In</button>
    </form>
    <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);"><a href="/login/forgot-password">Forgot your password?</a></p>
</div>
