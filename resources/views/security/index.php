<?php
/** @var array<int, array<string, mixed>> $recentAttempts */
/** @var array<int, array<string, mixed>> $ipRules */
/** @var array<int, array<string, mixed>> $countryRules */
/** @var array<int, array<string, mixed>> $accountLocks */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Security & Authentication Settings</h1>
    <p style="color:var(--cv-text-secondary);"><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Authentication & 2FA Configuration</h2>
    <form method="post" action="/admin/security/settings"><?= csrf_field() ?>
        <div class="cv-field">
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);font-weight:600;cursor:pointer;">
                <input type="checkbox" name="two_factor_enabled" value="1" <?= (!isset($twoFactorEnabled) || $twoFactorEnabled) ? 'checked' : '' ?>>
                Enable Two-Factor Authentication (2FA) for Client & Admin Login
            </label>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">When enabled, users with 2FA setup will be prompted for TOTP/recovery codes upon login.</p>
        </div>

        <hr style="border:0;border-top:1px solid var(--cv-border-default);margin:var(--cv-space-4) 0;">

        <h3 style="margin-top:0;font-size:var(--cv-text-md);">Google OAuth 2.0 Client Setup</h3>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-bottom:var(--cv-space-3);">
            To enable Google Sign-In: Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--cv-color-brand-500);">Google Cloud Console</a> &rsaquo; Create Credentials &rsaquo; OAuth client ID (Web Application).<br>
            Set Authorized redirect URIs to: <code style="background:var(--cv-bg-surface-sunken);padding:2px 6px;border-radius:4px;"><?= e(rtrim((string) ($config['app']['url'] ?? 'http://localhost'), '/')) ?>/client/auth/google/callback</code>
        </p>

        <div class="cv-field">
            <label class="cv-label">Google OAuth Client ID</label>
            <input class="cv-input" name="google_client_id" value="<?= e($googleClientId ?? '') ?>" placeholder="e.g. 1234567890-xxx.apps.googleusercontent.com">
        </div>

        <div class="cv-field">
            <label class="cv-label">Google OAuth Client Secret</label>
            <input class="cv-input" type="password" name="google_client_secret" value="<?= e($googleClientSecret ?? '') ?>" placeholder="e.g. GOCSPX-xxxxxxxxxxxx">
        </div>

        <button class="cv-btn" type="submit">Save Security Settings</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">IP Rules</h2>
    <table class="cv-table">
        <thead><tr><th>IP</th><th>Status</th><th>Tier</th><th>Source</th><th>Reason</th><th>Expires</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ipRules as $rule): ?>
            <tr>
                <td><?= e($rule['ip_address']) ?></td>
                <td>
                    <?php if ($rule['policy'] === 'whitelisted'): ?>
                        <span class="cv-badge cv-badge--king">King</span>
                    <?php elseif ($rule['policy'] === 'blacklisted'): ?>
                        <span class="cv-badge cv-badge--danger">Blocked</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Tracking (<?= (int) $rule['clean_session_count'] ?>/5)</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($rule['tier'] ?? '-')) ?></td>
                <td><?= e($rule['source']) ?></td>
                <td style="color:var(--cv-text-secondary);"><?= e((string) ($rule['reason'] ?? '')) ?></td>
                <td><?= e((string) ($rule['expires_at'] ?? 'never')) ?></td>
                <td>
                    <form method="post" action="/admin/security/ip/remove" style="display:inline;"><?= csrf_field() ?>
                        <input type="hidden" name="ip_address" value="<?= e($rule['ip_address']) ?>">
                        <button class="cv-btn cv-btn--secondary" type="submit">Clear</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($ipRules === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No IP rules yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="post" action="/admin/security/ip" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">IP Address</label>
            <input class="cv-input" name="ip_address" placeholder="203.0.113.5">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Action</label>
            <select class="cv-select" name="action">
                <option value="block">Block</option>
                <option value="whitelist">Whitelist</option>
            </select>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Reason</label>
            <input class="cv-input" name="reason" placeholder="Manual rule">
        </div>
        <button class="cv-btn" type="submit">Apply</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Country Rules</h2>
    <table class="cv-table">
        <thead><tr><th>Country</th><th>Policy</th></tr></thead>
        <tbody>
        <?php foreach ($countryRules as $rule): ?>
            <tr><td><?= e($rule['country_code']) ?></td><td><?= e($rule['policy']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($countryRules === []): ?>
            <tr><td colspan="2" style="color:var(--cv-text-secondary);">All countries are "Not Specified" (no explicit rules yet).</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="post" action="/admin/security/country" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Country Code</label>
            <input class="cv-input" name="country_code" placeholder="NG" maxlength="2">
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Policy</label>
            <select class="cv-select" name="policy">
                <option value="whitelisted">Whitelisted</option>
                <option value="not_specified">Not Specified</option>
                <option value="blacklisted">Blacklisted</option>
            </select>
        </div>
        <button class="cv-btn" type="submit">Set</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Locked Accounts</h2>
    <table class="cv-table">
        <thead><tr><th>Admin</th><th>Locked At</th><th>Expires</th><th>Reason</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accountLocks as $lock): ?>
            <tr>
                <td><?= e($lock['display_name']) ?> (<?= e($lock['username']) ?>)</td>
                <td><?= e($lock['locked_at']) ?></td>
                <td><?= e((string) ($lock['expires_at'] ?? 'never')) ?></td>
                <td style="color:var(--cv-text-secondary);"><?= e((string) ($lock['reason'] ?? '')) ?></td>
                <td>
                    <form method="post" action="/admin/security/unlock" style="display:inline;"><?= csrf_field() ?>
                        <input type="hidden" name="admin_id" value="<?= (int) $lock['admin_id'] ?>">
                        <button class="cv-btn cv-btn--secondary" type="submit">Unlock</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($accountLocks === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No locked accounts.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Live Log (last 50 attempts)</h2>
    <table class="cv-table">
        <thead><tr><th>Time</th><th>Username</th><th>IP</th><th>Country</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($recentAttempts as $attempt): ?>
            <tr>
                <td><?= e($attempt['created_at']) ?></td>
                <td><?= e((string) ($attempt['username'] ?? '-')) ?></td>
                <td><?= e($attempt['ip_address']) ?></td>
                <td><?= e((string) ($attempt['country_code'] ?? '-')) ?></td>
                <td>
                    <?php if ($attempt['successful']): ?>
                        <span class="cv-badge cv-badge--success">Success</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Failed</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recentAttempts === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No login attempts recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
