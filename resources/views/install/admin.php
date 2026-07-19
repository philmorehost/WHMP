<?php
/** @var array<int, string> $errors */
/** @var array<string, string> $old */
?>

<?php if (!empty($errors)): ?>
    <div class="alert-error">
        <strong>Validation Failed:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h1 class="cv-card__title">Create Admin Account</h1>
    <p style="color:var(--cv-text-secondary); margin-bottom: var(--cv-space-6);">Create your master administrator account. This account will have full access to configure and manage the entire WHMP installation.</p>

    <form method="post" action="/install/admin"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="username">Username</label>
            <input class="cv-input" id="username" name="username" value="<?= e($old['username'] ?? '') ?>" required minlength="3">
        </div>

        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="display_name">Display Name</label>
            <input class="cv-input" id="display_name" name="display_name" value="<?= e($old['displayName'] ?? '') ?>" placeholder="e.g. John Doe">
        </div>

        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="email">Email Address</label>
            <input class="cv-input" id="email" type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cv-space-4); margin-bottom: var(--cv-space-6);">
            <div class="cv-field">
                <label class="cv-label" for="password">Password</label>
                <input class="cv-input" id="password" type="password" name="password" required minlength="10" placeholder="Min 10 chars">
            </div>
            <div class="cv-field">
                <label class="cv-label" for="password_confirmation">Confirm Password</label>
                <input class="cv-input" id="password_confirmation" type="password" name="password_confirmation" required minlength="10">
            </div>
        </div>

        <button class="cv-btn" type="submit">Create Admin Account</button>
    </form>
</div>
