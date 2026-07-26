<?php
/** @var string|null $error */
?>
<div class="cv-card" style="max-width:28rem;margin:var(--cv-space-8) auto;box-sizing:border-box;">
    <h1 class="cv-card__title">Set Your Security PIN</h1>
    
    <div style="background:#fef2f2;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;margin-bottom:20px;color:#991b1b;font-size:var(--cv-text-sm);">
        <strong>🔒 Action Required:</strong> To keep your account safe and ensure you can self-recover if locked out, please create your 4+ character Security PIN below.
    </div>

    <?php if ($error !== null): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/client/set-pin" style="display:flex;flex-direction:column;gap:var(--cv-space-3);"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">New Security PIN (4+ chars) <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="password" name="security_pin" required minlength="4" autofocus placeholder="e.g. 1234" style="width:100%;box-sizing:border-box;">
        </div>

        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Confirm Security PIN <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" type="password" name="confirm_security_pin" required minlength="4" placeholder="Re-enter Security PIN" style="width:100%;box-sizing:border-box;">
        </div>

        <button class="cv-btn" type="submit" style="width:100%;margin-top:8px;">Save Security PIN &amp; Continue →</button>
    </form>
</div>
