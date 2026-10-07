<?php
/** @var string|null $error */
/** @var string $refCode */
/**
 * @var string|null $accountExists 'same_site' (you already have an account HERE — sign in) or
 *                                 'other_provider' (it belongs to another provider on this platform).
 *                                 Never says which provider: that is another business's customer list.
 */
$accountExists ??= null;
?>
<div class="cv-card" style="max-width:30rem;margin:var(--cv-space-8) auto;box-sizing:border-box;">
    <h1 class="cv-card__title">Create Account</h1>

    <?php if ($accountExists === 'same_site'): ?>
        <div class="cv-alert cv-alert--neutral" role="alert" style="margin-bottom:var(--cv-space-3);">
            <strong>You already have an account with this email address.</strong><br>
            Please <a href="/client/login">sign in</a> instead, or
            <a href="/client/forgot-password">reset your password</a> if you have forgotten it.
        </div>
    <?php elseif ($accountExists === 'other_provider'): ?>
        <div class="cv-alert cv-alert--warning" role="alert" style="margin-bottom:var(--cv-space-3);">
            <strong>This email address is already registered with another provider on this platform.</strong><br>
            Please sign in on the website where you created that account. If you would like to move it here,
            sign in there and open a support ticket asking for an <strong>account move</strong> — your services,
            domains, invoices and ticket history move with it. Or use a different email address to register here.
        </div>
    <?php elseif ($error): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($googleUser)): ?>
        <div style="margin-bottom:var(--cv-space-4);padding:var(--cv-space-3);background:var(--cv-bg-surface-secondary);border-radius:var(--cv-radius);border:1px solid var(--cv-border-default);">
            <p style="margin:0;font-size:var(--cv-text-sm);color:var(--cv-text-primary);">
                <strong>Almost there!</strong> Please provide your address and phone number to complete your Google sign up.
            </p>
        </div>
    <?php endif; ?>

    <?= $view->partial('partials.free-reseller-resume-note') ?>
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
        <?= $view->render('partials.google-signin-button', ['label' => 'Sign up with Google']) ?>
    <?php endif; ?>

    <p style="margin-top:var(--cv-space-3);font-size:var(--cv-text-sm);">Already have an account? <a href="/client/login">Log in</a></p>
</div>
