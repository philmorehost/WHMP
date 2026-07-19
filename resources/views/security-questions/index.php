<?php
/** @var array<int, array{slug: string, metadata: array{name: string, description: string, version: string, author: string}, active: bool}> $questions */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Security Questions</h1>
    <p style="color:var(--cv-text-secondary);">Installable knowledge-based identity verification factors (blueprint §3/§4.3 <code>SecurityQuestionModule</code> SDK). An activated question can be chosen by clients on their Security tab and used as an extra step during password reset; deactivating hides it from new setups (clients who already configured it keep working until an admin clears their answer).</p>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Security Question</th><th>Version</th><th>Author</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($questions as $question): ?>
            <tr>
                <td>
                    <strong><?= e($question['metadata']['name']) ?></strong>
                    <div style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);"><?= e($question['metadata']['description']) ?></div>
                </td>
                <td><?= e($question['metadata']['version']) ?></td>
                <td><?= e($question['metadata']['author']) ?></td>
                <td>
                    <?php if ($question['active']): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($question['active']): ?>
                        <form method="post" action="/admin/security-questions/<?= e($question['slug']) ?>/deactivate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Deactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/security-questions/<?= e($question['slug']) ?>/activate" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Activate</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($questions === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No security questions registered.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
