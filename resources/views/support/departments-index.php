<?php
/** @var array<int, array<string, mixed>> $departments */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Departments</h1>
    <p><a href="/admin/tickets">&larr; Back to tickets</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Email (IMAP)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($departments as $department): ?>
            <tr>
                <td><?= e($department['name']) ?></td>
                <td><?= e((string) ($department['email'] ?? '-')) ?></td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <button type="button" class="cv-btn cv-btn--secondary"
                        data-edit-trigger
                        data-edit-form="#department-form"
                        data-edit-fields="<?= e(json_encode(['name' => $department['name'], 'email' => (string) ($department['email'] ?? '')])) ?>"
                        data-edit-action="/admin/departments/<?= (int) $department['id'] ?>"
                        data-edit-submit-label="Update"
                        data-edit-title="Edit Department"
                        data-edit-title-target="#department-form-title">Edit</button>
                    <form method="post" action="/admin/departments/<?= (int) $department['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($departments === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No departments yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3 id="department-form-title">Add Department</h3>
    <form id="department-form" method="post" action="/admin/departments" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" id="department-name" required>
        </div>
        <div class="cv-field" style="margin-bottom:0;">
            <label class="cv-label">Email (optional, for IMAP piping)</label>
            <input class="cv-input" type="email" name="email" id="department-email">
        </div>
        <button class="cv-btn" type="submit" data-edit-submit>Add</button>
        <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
            data-edit-cancel
            data-edit-reset-action="/admin/departments"
            data-edit-reset-label="Add"
            data-edit-reset-title="Add Department"
            data-edit-title-target="#department-form-title">Cancel</button>
    </form>
</div>
