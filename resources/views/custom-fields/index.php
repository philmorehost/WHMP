<?php
/** @var array<int, array<string, mixed>> $fields */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Custom Client Fields</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">Fields</h2>
        <a class="cv-btn" href="/admin/custom-fields/create">Add Field</a>
    </div>
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Type</th><th>Required</th><th>Admin Only</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($fields as $field): ?>
            <tr>
                <td><?= e($field['name']) ?></td>
                <td><?= e($field['type']) ?></td>
                <td><?= $field['required'] ? 'Yes' : 'No' ?></td>
                <td><?= $field['admin_only'] ? 'Yes' : 'No' ?></td>
                <td>
                    <a class="cv-btn cv-btn--secondary" href="/admin/custom-fields/<?= (int) $field['id'] ?>/edit">Edit</a>
                    <form method="post" action="/admin/custom-fields/<?= (int) $field['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($fields === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No custom fields defined yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
