<?php
/** @var array<int, array{label: string, ok: bool, detail: string}> $checks */
/** @var bool $allPassed */
/** @var array<string> $errors */
/** @var array<string, string> $old */
?>

<?php if (!empty($errors)): ?>
    <div class="alert-error">
        <strong>Please correct the following errors:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h1 class="cv-card__title">Welcome</h1>
    <p style="color:var(--cv-text-secondary); margin-bottom: 0;">This wizard will check your server, verify your activation key, set up your database, and configure the administrator account.</p>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">System Requirements</h2>
    <table class="cv-table">
        <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td style="font-weight: 600;"><?= e($check['label']) ?></td>
                <td>
                    <span class="cv-badge <?= $check['ok'] ? 'cv-badge--success' : 'cv-badge--danger' ?>">
                        <?= $check['ok'] ? 'OK' : 'FAIL' ?>
                    </span>
                </td>
                <td style="color:var(--cv-text-secondary); font-size: var(--cv-text-sm);"><?= e($check['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Product Activation</h2>
    <p style="color:var(--cv-text-secondary); margin-bottom: var(--cv-space-4);">Please enter your product activation key to continue. Activation is required for verification and support eligibility.</p>

    <?php if ($allPassed): ?>
        <form method="post" action="/install"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
                <label class="cv-label" for="activation_key">Activation Key</label>
                <input class="cv-input" id="activation_key" name="activation_key" placeholder="XXXX-XXXX-XXXX-XXXX" value="<?= e($old['activation_key'] ?? '') ?>" required>
            </div>
            <button class="cv-btn" type="submit">Verify & Continue</button>
        </form>
    <?php else: ?>
        <div style="background: rgba(220, 38, 38, 0.1); border: 1px solid rgba(220, 38, 38, 0.2); border-radius: var(--cv-radius-md); padding: var(--cv-space-4); text-align: center; color: #f87171;">
            Please address the failing checks above to proceed with the installation.
        </div>
    <?php endif; ?>
</div>
