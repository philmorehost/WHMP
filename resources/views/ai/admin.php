<?php
/** @var string $provider */
/** @var string $model */
/** @var string $maskedKey */
/** @var bool $hasKey */
/** @var array<int, array{slug: string, label: string, enabled: bool}> $features */
/** @var array{calls: int, tokens: int, failures: int, calls_30d: int, tokens_30d: int} $totals */
/** @var array<int, array<string, mixed>> $byFeature */
/** @var array<int, array<string, mixed>> $recent */
/** @var bool $saved */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">AI Copilot</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:0;">
        Configure the AI provider and control which parts of WHMP use AI. Usage is metered below so you can keep an eye on token spend.
    </p>
</div>

<?php if ($saved): ?>
    <div class="cv-badge cv-badge--success" style="display:block;padding:var(--cv-space-3);margin-bottom:var(--cv-space-4);">AI settings saved.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);"><?= e($error) ?></div>
<?php endif; ?>

<!-- Usage summary -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--cv-space-3);margin-bottom:var(--cv-space-4);">
    <div class="cv-card" style="text-align:center;">
        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;">Calls (30d)</div>
        <strong style="font-size:var(--cv-text-xl);"><?= number_format($totals['calls_30d']) ?></strong>
    </div>
    <div class="cv-card" style="text-align:center;">
        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;">Tokens (30d)</div>
        <strong style="font-size:var(--cv-text-xl);"><?= number_format($totals['tokens_30d']) ?></strong>
    </div>
    <div class="cv-card" style="text-align:center;">
        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;">Calls (all time)</div>
        <strong style="font-size:var(--cv-text-xl);"><?= number_format($totals['calls']) ?></strong>
    </div>
    <div class="cv-card" style="text-align:center;">
        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;">Failures</div>
        <strong style="font-size:var(--cv-text-xl);color:<?= $totals['failures'] > 0 ? 'var(--cv-color-danger)' : 'inherit' ?>;"><?= number_format($totals['failures']) ?></strong>
    </div>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Provider &amp; Features</h2>
    <form method="post" action="/admin/ai"><?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">Provider</label>
                <select class="cv-select" name="provider">
                    <option value="deepseek" <?= $provider === 'deepseek' ? 'selected' : '' ?>>DeepSeek</option>
                </select>
            </div>
            <div class="cv-field">
                <label class="cv-label">Model</label>
                <input class="cv-input" name="model" value="<?= e($model) ?>" placeholder="deepseek-chat">
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">API Key <?= $hasKey ? '<span style="color:var(--cv-text-secondary);font-weight:400;">(currently set: ' . e($maskedKey) . ')</span>' : '' ?></label>
            <input class="cv-input" type="password" name="api_key" placeholder="<?= $hasKey ? 'Leave blank to keep the current key' : 'Enter your provider API key' ?>" autocomplete="off">
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">
                Stored securely and never displayed in full. Falls back to the <code>DEEPSEEK_API_KEY</code> environment variable if left unset.
            </p>
        </div>

        <h3 style="font-family:'Hanken Grotesk',sans-serif;font-size:var(--cv-text-base);margin-top:var(--cv-space-3);">Enabled AI features</h3>
        <div style="display:grid;gap:var(--cv-space-2);margin-bottom:var(--cv-space-3);">
            <?php foreach ($features as $f): ?>
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="features[]" value="<?= e($f['slug']) ?>" <?= $f['enabled'] ? 'checked' : '' ?>>
                    <?= e($f['label']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="cv-btn" type="submit">Save AI Settings</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Usage by feature</h2>
    <table class="cv-table">
        <thead><tr><th>Feature</th><th>Calls</th><th>Tokens</th></tr></thead>
        <tbody>
        <?php foreach ($byFeature as $row): ?>
            <tr><td><?= e((string) $row['feature']) ?></td><td><?= number_format((int) $row['calls']) ?></td><td><?= number_format((int) $row['tokens']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($byFeature === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);text-align:center;">No AI usage recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Recent AI calls</h2>
    <table class="cv-table">
        <thead><tr><th>When</th><th>Feature</th><th>Provider</th><th>Tokens</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
            <tr>
                <td><?= e((string) $row['created_at']) ?></td>
                <td><?= e((string) $row['feature']) ?></td>
                <td><?= e((string) $row['provider']) ?></td>
                <td><?= number_format((int) $row['total_tokens']) ?></td>
                <td><?= ((int) $row['success'] === 1) ? '<span class="cv-badge cv-badge--success">OK</span>' : '<span class="cv-badge cv-badge--danger">Failed</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recent === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);text-align:center;">No AI calls yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
