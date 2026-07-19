<?php
/** @var array<int, array<string, mixed>> $templates */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Email Templates</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/email-log">View Email Log</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Key</th><th>Subject</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $tpl): ?>
            <tr>
                <td><?= e($tpl['name']) ?></td>
                <td><code><?= e($tpl['key']) ?></code></td>
                <td><?= e($tpl['subject']) ?></td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/email-templates/<?= (int) $tpl['id'] ?>/edit">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($templates === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No templates seeded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
