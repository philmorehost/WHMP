<?php
/** @var string|null $error */
/** @var string $refCode */
?>
<div class="cv-card" style="max-width:30rem;margin:var(--cv-space-8) auto;box-sizing:border-box;">
    <h1 class="cv-card__title">Create Account</h1>

    <?php if ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($googleUser)): ?>
        <div style="margin-bottom:var(--cv-space-4);padding:var(--cv-space-3);background:var(--cv-bg-surface-secondary);border-radius:var(--cv-radius);border:1px solid var(--cv-border-default);">
            <p style="margin:0;font-size:var(--cv-text-sm);color:var(--cv-text-primary);">
                <strong>Almost there!</strong> Please provide your address and phone number to complete your Google sign up.
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="/client/register" style="display:flex;flex-direction:column;gap:var(--cv-space-3);width:100%;box-sizing:border-box;"><?= csrf_field() ?>
        <input type="hidden" name="ref" value="<?= e($refCode) ?>">
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">First Name <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" name="first_name" required value="<?= e($googleUser['first_name'] ?? '') ?>" <?= !empty($googleUser['first_name']) ? 'readonly' : '' ?> style="width:100%;box-sizing:border-box;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Last Name <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" name="last_name" required value="<?= e($googleUser['last_name'] ?? '') ?>" <?= !empty($googleUser['last_name']) ? 'readonly' : '' ?> style="width:100%;box-sizing:border-box;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Email <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="email" name="email" required value="<?= e($googleUser['email'] ?? '') ?>" <?= !empty($googleUser) ? 'readonly' : '' ?> style="width:100%;box-sizing:border-box;">
        </div>
        <?php if (empty($googleUser)): ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Password <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="password" name="password" required style="width:100%;box-sizing:border-box;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Security PIN (4+ chars) <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="password" name="security_pin" required minlength="4" style="width:100%;box-sizing:border-box;">
            <div style="font-size:12px;color:var(--cv-text-secondary);margin-top:4px;">Used to recover your account if it gets blocked.</div>
        </div>
        <?php else: ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Security PIN (4+ chars) <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="password" name="security_pin" required minlength="4" style="width:100%;box-sizing:border-box;">
            <div style="font-size:12px;color:var(--cv-text-secondary);margin-top:4px;">Used to recover your account if it gets blocked.</div>
        </div>
        <?php endif; ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Street Address <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" name="address1" placeholder="e.g. 123 Main Street" required style="width:100%;box-sizing:border-box;">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--cv-space-3); width:100%; box-sizing:border-box;">
            <div class="cv-field" style="margin-bottom:0; width:100%;">
                <label class="cv-label">City <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                <input class="cv-input" name="city" placeholder="e.g. Lagos" required style="width:100%;box-sizing:border-box;">
            </div>
            <div class="cv-field" style="margin-bottom:0; width:100%;">
                <label class="cv-label">Postal / Zip Code <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                <input class="cv-input" name="postcode" placeholder="e.g. 100001" required style="width:100%;box-sizing:border-box;">
            </div>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Country <span style="color:var(--cv-text-secondary);font-weight:normal;">(optional)</span></label>
            <input class="cv-input" name="country" placeholder="e.g. NG" maxlength="2" style="width:100%;box-sizing:border-box;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Phone Number <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" name="phone" placeholder="e.g. +234 801 234 5678" required style="width:100%;box-sizing:border-box;">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">VAT Number <span style="color:var(--cv-text-secondary);font-weight:normal;">(optional, business accounts)</span></label>
            <input class="cv-input" name="vat_number" placeholder="e.g. DE123456789" style="width:100%;box-sizing:border-box;">
        </div>
        <button class="cv-btn" type="submit" style="width:100%;margin-top:var(--cv-space-2);box-sizing:border-box;">Create Account</button>
    </form>

    <?php if (empty($googleUser) && !empty($googleClientId)): ?>
        <div style="margin:var(--cv-space-4) 0;display:flex;align-items:center;gap:var(--cv-space-2);">
            <div style="flex:1;border-bottom:1px solid var(--cv-border-default);"></div>
            <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">OR</span>
            <div style="flex:1;border-bottom:1px solid var(--cv-border-default);"></div>
        </div>
        <a href="/client/auth/google" class="cv-btn cv-btn--secondary" style="width:100%;display:flex;align-items:center;justify-content:center;gap:var(--cv-space-2);text-decoration:none;">
            <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
            Sign up with Google
        </a>
    <?php endif; ?>

    <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);">Already have an account? <a href="/client/login">Log in</a></p>
</div>
