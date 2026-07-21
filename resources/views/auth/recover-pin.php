<?php
/** @var string|null $error */
/** @var bool $success */
?>
<div class="cv-card" style="max-width:26rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">Recover Admin Account</h1>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="cv-field-success" style="margin-bottom:var(--cv-space-3);">Your account and IP have been unblocked! If you have forgotten your password, please proceed to reset it. Otherwise, you can return to the login page.</div>
        <p>
            <a class="cv-btn" href="/login/forgot-password" style="margin-bottom:10px;">Reset Password</a>
        </p>
        <p>
            <a href="/login">&larr; Back to login</a>
        </p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);">Enter your username and your 4+ character Security PIN to unblock your account.</p>
        <form method="post" action="/login/recover-pin"><?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label">Username</label>
                <input class="cv-input" type="text" name="username" autofocus required>
            </div>
            <div class="cv-field">
                <label class="cv-label">Security PIN</label>
                <input class="cv-input" type="password" name="security_pin" required>
            </div>
            <button class="cv-btn" type="submit">Unblock Account</button>
        </form>
        <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);"><a href="/login">&larr; Back to login</a></p>
    <?php endif; ?>
</div>
