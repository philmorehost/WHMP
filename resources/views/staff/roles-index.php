<?php
/** @var array<int, array<string, mixed>> $roles */
/** @var string|null $error */
?>
<style>
.admin-role-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}
.admin-role-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-role-hero__content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.admin-role-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
}
.admin-role-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-role-btn-create {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-role-btn-create:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-role-alert {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 8px;
    padding: 12px 16px;
    color: #ef4444;
    margin-bottom: 24px;
    font-size: .9rem;
}
.admin-role-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-role-table-wrapper {
    overflow-x: auto;
}
.admin-role-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-role-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-role-table th {
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-role-table td {
    padding: 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-role-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-role-badge--super {
    background: linear-gradient(135deg, rgba(168,85,247,.2), rgba(147,51,234,.15));
    color: #a855f7;
    border: 1px solid rgba(168,85,247,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-role-badge--scoped {
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    color: #3b82f6;
    border: 1px solid rgba(59,130,246,.3);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
}
.admin-role-btn {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}
.admin-role-btn:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-role-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-role-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
@media (max-width: 768px) {
    .admin-role-hero {
        flex-direction: column;
        padding: 32px 24px;
        align-items: flex-start;
    }
    .admin-role-hero__title {
        font-size: 1.5rem;
    }
    .admin-role-btn-create {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="admin-role-hero">
    <div class="admin-role-hero__content">
        <a href="/admin/staff" class="admin-role-hero__back">
            <span>←</span>
            <span>Back to Staff</span>
        </a>
        <h1 class="admin-role-hero__title">👤 Roles & Permissions</h1>
    </div>
    <a href="/admin/roles/create" class="admin-role-btn-create">➕ Add Role</a>
</div>

<?php if (!empty($error)): ?>
    <div class="admin-role-alert">
        ⚠️ <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="admin-role-card">
    <div style="padding:24px;border-bottom:1px solid var(--cv-border-default);">
        <h2 style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.25rem;color:var(--cv-text-primary);margin:0;">👥 Permission Matrix</h2>
    </div>
    <div class="admin-role-table-wrapper">
        <table class="admin-role-table">
            <thead><tr><th>Role Name</th><th>Type</th><th>Permissions</th><th style="width:150px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td><strong><?= e($role['name']) ?></strong></td>
                    <td>
                        <?php if ($role['is_super_admin']): ?>
                            <span class="admin-role-badge--super">👑 Super Admin</span>
                        <?php else: ?>
                            <span class="admin-role-badge--scoped">🔒 Scoped</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--cv-text-secondary);font-size:.85rem;">
                        <?= $role['is_super_admin'] ? '<em>All permissions</em>' : (e(implode(', ', $role['permissions']) ?: 'None')) ?>
                    </td>
                    <td style="display:flex;gap:6px;">
                        <a class="admin-role-btn" href="/admin/roles/<?= (int) $role['id'] ?>/edit">✏️ Edit</a>
                        <form method="post" action="/admin/roles/<?= (int) $role['id'] ?>/delete" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="admin-role-btn--danger" type="submit">🗑️ Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
