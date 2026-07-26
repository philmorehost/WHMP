<?php
/** @var array<string, mixed> $client */
/** @var string|null $error */
/** @var string|null $success */
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">My Account</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a> &middot; <a href="/client/account/security">Two-Factor Authentication</a> &middot; <a href="/client/account/privacy">Privacy &amp; Your Data</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($success !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-success"><?= e($success) ?></div>
    </div>
<?php endif; ?>

<?php if (empty($client['security_pin_hash'])): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);background:<?= isset($_GET['pin_required']) ? '#fef2f2' : '#fffbeb' ?>;border:1px solid <?= isset($_GET['pin_required']) ? '#fecaca' : '#fef3c7' ?>;">
        <div style="color:<?= isset($_GET['pin_required']) ? '#991b1b' : '#b45309' ?>;font-size:var(--cv-text-sm);">
            <strong><?= isset($_GET['pin_required']) ? '🔒 Security PIN Required:' : '⚠️ Security Action Recommended:' ?></strong> You must set a Security PIN before continuing to use your account. Setting a Security PIN ensures you can self-recover if your account gets locked out. Scroll down to the <strong>Change Security PIN</strong> section below.
        </div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Contact Details</h2>
    <form method="post" action="/client/account"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" value="<?= e($client['email']) ?>" required>
        </div>
        <div style="display:flex;gap:var(--cv-space-3);">
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">First Name</label>
                <input class="cv-input" name="first_name" value="<?= e($client['first_name']) ?>" required>
            </div>
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">Last Name</label>
                <input class="cv-input" name="last_name" value="<?= e($client['last_name']) ?>" required>
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">Company</label>
            <input class="cv-input" name="company_name" value="<?= e((string) ($client['company_name'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Address <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
            <input class="cv-input" name="address1" value="<?= e((string) ($client['address1'] ?? '')) ?>" required style="margin-bottom:var(--cv-space-2);">
            <input class="cv-input" name="address2" value="<?= e((string) ($client['address2'] ?? '')) ?>" placeholder="Address Line 2 (optional)">
        </div>
        <div style="display:flex;gap:var(--cv-space-3);">
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">City <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                <input class="cv-input" name="city" value="<?= e((string) ($client['city'] ?? '')) ?>" required>
            </div>
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">State / Province</label>
                <input class="cv-input" name="state" value="<?= e((string) ($client['state'] ?? '')) ?>">
            </div>
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">Postcode <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                <input class="cv-input" name="postcode" value="<?= e((string) ($client['postcode'] ?? '')) ?>" required>
            </div>
        </div>
        <div style="display:flex;gap:var(--cv-space-3);">
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">Country</label>
                <input class="cv-input" name="country" value="<?= e((string) ($client['country'] ?? '')) ?>">
            </div>
            <div class="cv-field" style="flex:1;">
                <label class="cv-label">Phone <span style="color:var(--cv-color-danger, #ef4444);">*</span></label>
                <input class="cv-input" name="phone" value="<?= e((string) ($client['phone'] ?? '')) ?>" required>
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">VAT Number</label>
            <input class="cv-input" name="vat_number" value="<?= e((string) ($client['vat_number'] ?? '')) ?>" placeholder="e.g. DE123456789">
            <?php if (!empty($client['vat_verified_at'])): ?>
                <p style="margin:var(--cv-space-1) 0 0;font-size:var(--cv-text-xs);">
                    <?php if ((int) $client['vat_verified_valid'] === 1): ?>
                        <span class="cv-badge cv-badge--success">VIES verified</span>
                        <?php if (!empty($client['vat_verified_name'])): ?> as "<?= e((string) $client['vat_verified_name']) ?>"<?php endif; ?>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">VIES rejected</span>
                    <?php endif; ?>
                    on <?= e((string) $client['vat_verified_at']) ?>
                </p>
            <?php endif; ?>
        </div>
        <button class="cv-btn" type="submit">Save Details</button>
    </form>
    <form method="post" action="/client/account/verify-vat" style="margin-top:var(--cv-space-3);"><?= csrf_field() ?>
        <button class="cv-btn cv-btn--secondary" type="submit">Verify VAT Number via VIES</button>
    </form>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Change Password</h2>
    <form method="post" action="/client/account/password"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Current Password</label>
            <input class="cv-input" type="password" name="current_password" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">New Password</label>
            <input class="cv-input" type="password" name="new_password" required minlength="8">
        </div>
        <button class="cv-btn" type="submit">Change Password</button>
    </form>
</div>

<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-4) auto;">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Change Security PIN</h2>
    <form method="post" action="/client/account/security-pin"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Current Password <span style="color:var(--cv-text-secondary);">(for verification)</span></label>
            <input class="cv-input" type="password" name="current_password" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">New Security PIN (4+ chars)</label>
            <input class="cv-input" type="password" name="new_security_pin" required minlength="4">
        </div>
        <button class="cv-btn" type="submit">Change Security PIN</button>
    </form>
</div>
