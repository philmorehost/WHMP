<?php
/** @var string $token */
/** @var string|null $error */
?>
<div class="cv-card">
    <h1 class="cv-card__title">Choose a New Password</h1>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login/password/reset/<?= e($token) ?>"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">New Password</label>
            <input class="cv-input" type="password" name="new_password" autofocus required minlength="8">
        </div>
        <button class="cv-btn" type="submit">Reset Password</button>
    </form>
</div>
