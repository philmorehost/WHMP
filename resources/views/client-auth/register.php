<?php
/** @var string|null $error */
/** @var string $refCode */
?>
<div class="cv-card" style="max-width:26rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">Create Account</h1>

    <?php if ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/client/register"><?= csrf_field() ?>
        <input type="hidden" name="ref" value="<?= e($refCode) ?>">
        <div class="cv-field">
            <label class="cv-label">First Name</label>
            <input class="cv-input" name="first_name" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Last Name</label>
            <input class="cv-input" name="last_name" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Password</label>
            <input class="cv-input" type="password" name="password" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Country <span style="color:var(--cv-text-secondary);font-weight:normal;">(optional)</span></label>
            <input class="cv-input" name="country" placeholder="e.g. DE" maxlength="2">
        </div>
        <div class="cv-field">
            <label class="cv-label">VAT Number <span style="color:var(--cv-text-secondary);font-weight:normal;">(optional, business accounts)</span></label>
            <input class="cv-input" name="vat_number" placeholder="e.g. DE123456789">
        </div>
        <button class="cv-btn" type="submit">Create Account</button>
    </form>
    <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);">Already have an account? <a href="/client/login">Log in</a></p>
</div>
