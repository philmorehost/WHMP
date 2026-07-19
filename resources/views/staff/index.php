<?php
/** @var array<int, array<string, mixed>> $admins */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Staff</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/roles">Manage Roles</a></p>
</div>

<?php if (!empty($error)): ?>
    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
<?php endif; ?>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <h2 class="cv-card__title" style="margin:0;">Admin Accounts</h2>
        <?= $view->partial('partials.table-search', ['target' => '#staff-table', 'placeholder' => 'Search staff...']) ?>
        <a class="cv-btn" href="/admin/staff/create">Add Staff</a>
    </div>
    <table class="cv-table" id="staff-table">
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $admin): ?>
            <tr>
                <td><?= e($admin['display_name']) ?></td>
                <td><?= e($admin['username']) ?></td>
                <td><?= e($admin['email']) ?></td>
                <td><?= e((string) ($admin['role_name'] ?? 'None')) ?></td>
                <td>
                    <a class="cv-btn cv-btn--secondary" href="/admin/staff/<?= (int) $admin['id'] ?>/edit">Edit</a>
                    <form method="post" action="/admin/staff/<?= (int) $admin['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
