<?php
/** @var array<int, array<string, mixed>> $credentials */
/** @var array<int, string> $scopeCatalog */
/** @var string|null $newCredential */
/** @var string|null $newSecret */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">API Credentials</h1>
    <p style="color:var(--cv-text-secondary);">Issue scoped key/secret pairs for the external REST API (<code>/api/&hellip;</code>). Each credential carries its own permission scopes, so an integration only sees what it needs. The plaintext secret is shown <strong>once</strong> after creation — it is never stored, only its hash is.</p>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-badge cv-badge--danger" style="display:block;padding:var(--cv-space-3);margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($newCredential !== null && $newSecret !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);border:1px solid var(--cv-color-success-600, #22c55e);">
        <h2 class="cv-card__title">Credential "<?= e($newCredential) ?>" created</h2>
        <p style="color:var(--cv-text-secondary);">Copy these now — the secret is shown only this one time:</p>
        <p style="font-family:monospace;background:var(--cv-color-brand-50);padding:var(--cv-space-3);border-radius:var(--cv-radius-sm);word-break:break-all;user-select:all;"><?= e($newSecret) ?></p>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">Send <code>Authorization: Bearer &lt;key.secret&gt;</code> on every request.</p>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Create a new credential</h2>
    <form method="post" action="/admin/api-credentials"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
            <label class="cv-label">Label</label>
            <input class="cv-input" name="label" placeholder="e.g. Reseller dashboard integration" required style="max-width:420px;">
        </div>
        <div class="cv-field">
            <label class="cv-label">Scopes</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:var(--cv-space-2);">
                <?php foreach ($scopeCatalog as $scope): ?>
                    <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;font-size:var(--cv-text-sm);">
                        <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>"> <code><?= e($scope) ?></code>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-3);">Generate key &amp; secret</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Existing credentials</h2>
    <?php if ($credentials === []): ?>
        <p style="color:var(--cv-text-secondary);">No API credentials yet — create one above to start integrating.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead><tr><th>Label</th><th>Key</th><th>Scopes</th><th>Status</th><th>Last used</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($credentials as $credential): ?>
                <tr>
                    <td><strong><?= e($credential['label']) ?></strong></td>
                    <td><code><?= e($credential['api_key']) ?></code></td>
                    <td style="font-size:var(--cv-text-xs);">
                        <?php
                        $scopes = json_decode((string) $credential['scopes'], true);
                        $scopes = is_array($scopes) ? $scopes : [];
                        if (in_array('*', $scopes, true)): ?>
                            <span class="cv-badge cv-badge--neutral">All scopes</span>
                        <?php else: ?>
                            <?= e(implode(', ', $scopes)) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $credential['active'] === 1): ?>
                            <span class="cv-badge cv-badge--success">Active</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--danger">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--cv-text-xs);">
                        <?= !empty($credential['last_used_at']) ? e($credential['last_used_at']) : 'Never' ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <form method="post" action="/admin/api-credentials/<?= (int) $credential['id'] ?>/active" style="display:inline;"><?= csrf_field() ?>
                            <input type="hidden" name="active" value="<?= (int) $credential['active'] === 1 ? '0' : '1' ?>">
                            <button class="cv-btn cv-btn--secondary" type="submit"><?= (int) $credential['active'] === 1 ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" action="/admin/api-credentials/<?= (int) $credential['id'] ?>/delete" style="display:inline;" data-confirm="Delete this API credential? Integrations using it will immediately stop working."><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
