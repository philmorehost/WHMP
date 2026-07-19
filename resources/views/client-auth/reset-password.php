<?php
/** @var string $token */
/** @var string|null $error */
/** @var array{slug: string, question: string}|null $securityQuestion */
?>
<div class="cv-card" style="max-width:26rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">Choose a New Password</h1>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/client/password/reset/<?= e($token) ?>"><?= csrf_field() ?>
        <?php if ($securityQuestion !== null): ?>
            <div class="cv-field">
                <label class="cv-label"><?= e($securityQuestion['question']) ?></label>
                <input class="cv-input" type="text" name="security_answer" required>
            </div>
        <?php endif; ?>
        <div class="cv-field">
            <label class="cv-label">New Password</label>
            <input class="cv-input" type="password" name="new_password" <?= $securityQuestion === null ? 'autofocus' : '' ?> required minlength="8">
        </div>
        <button class="cv-btn" type="submit">Reset Password</button>
    </form>
</div>
