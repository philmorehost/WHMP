<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var string|null $error */
?>
<style>
.admin-pg-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-pg-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-pg-hero__back {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
}
.admin-pg-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-pg-alert {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 8px;
    padding: 12px 16px;
    color: #ef4444;
    margin-bottom: 24px;
}
.admin-pg-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-pg-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-pg-card__body {
    padding: 24px;
}
.admin-pg-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
    align-items: end;
}
.admin-pg-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-pg-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
}
.admin-pg-field input {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
}
.admin-pg-field input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-pg-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-pg-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-pg-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-pg-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-pg-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-pg-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-pg-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-pg-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-pg-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-pg-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-pg-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
</style>

<div class="admin-pg-hero">
    <a href="/admin/products" class="admin-pg-hero__back">← Back to Products</a>
    <h1 class="admin-pg-hero__title">📁 Product Groups</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="admin-pg-alert">⚠️ <?= e($error) ?></div>
<?php endif; ?>

<div class="admin-pg-card">
    <h2 class="admin-pg-card__title" id="product-group-form-title">➕ Add Group</h2>
    <div class="admin-pg-card__body">
        <form id="product-group-form" method="post" action="/admin/products/groups" class="admin-pg-form"><?= csrf_field() ?>
            <div class="admin-pg-field">
                <label>Group Name</label>
                <input name="name" id="product-group-name" required>
            </div>
            <div class="admin-pg-field">
                <label>Description (optional)</label>
                <input name="description" id="product-group-description">
            </div>
            <div style="display:flex;gap:12px;">
                <button class="admin-pg-btn" type="submit" data-edit-submit>➕ Add Group</button>
                <button class="admin-pg-btn--secondary" type="button" style="display:none;padding:8px 16px;border-radius:6px;border:1px solid var(--cv-border-default);"
                    data-edit-cancel
                    data-edit-form="#product-group-form"
                    data-edit-reset-action="/admin/products/groups"
                    data-edit-reset-label="Add Group"
                    data-edit-reset-title="Add Group"
                    data-edit-title-target="#product-group-form-title">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="admin-pg-card">
    <h2 class="admin-pg-card__title">📋 Groups</h2>
    <div class="admin-pg-card__body" style="padding:0;overflow-x:auto;">
        <table class="admin-pg-table">
            <thead><tr><th>Name</th><th>Description</th><th style="width:120px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $group): ?>
                <tr>
                    <td><strong><?= e($group['name']) ?></strong></td>
                    <td style="color:var(--cv-text-secondary);font-size:.85rem;"><?= e((string) ($group['description'] ?? '')) ?></td>
                    <td style="display:flex;gap:8px;">
                        <button type="button" class="admin-pg-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);"
                            data-edit-trigger
                            data-edit-form="#product-group-form"
                            data-edit-fields="<?= e(json_encode(['name' => $group['name'], 'description' => (string) ($group['description'] ?? '')])) ?>"
                            data-edit-action="/admin/products/groups/<?= (int) $group['id'] ?>/edit"
                            data-edit-submit-label="Update Group"
                            data-edit-title="Edit Group"
                            data-edit-title-target="#product-group-form-title">✏️ Edit</button>
                    <form method="post" action="/admin/products/groups/<?= (int) $group['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No product groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
