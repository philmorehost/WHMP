<?php
/** @var array<int, array<string, mixed>> $groups */
?>
<style>
.admin-og-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-og-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-og-hero__back {
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
.admin-og-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-og-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-og-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-og-card__body {
    padding: 24px;
}
.admin-og-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}
.admin-og-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
}
.admin-og-field input {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    width: 100%;
    box-sizing: border-box;
}
.admin-og-field input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-og-btn {
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
.admin-og-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-og-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-og-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-og-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-og-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-og-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-og-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-og-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-og-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-og-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
</style>

<div class="admin-og-hero">
    <a href="/admin/products" class="admin-og-hero__back">← Back to Products</a>
    <h1 class="admin-og-hero__title">⚙️ Option Groups</h1>
</div>

<form id="bulk-delete-groups-form" method="post" action="/admin/configurable-options/bulk-delete" data-confirm="Are you sure you want to delete all selected option groups? This will delete all of their options too.">
    <?= csrf_field() ?>
</form>

<div class="admin-og-card">
    <h2 class="admin-og-card__title" id="option-group-form-title">➕ Add Group</h2>
    <div class="admin-og-card__body">
        <form id="option-group-form" method="post" action="/admin/configurable-options"><?= csrf_field() ?>
            <div class="admin-og-field">
                <label>Group Name</label>
                <input name="name" id="option-group-name" required>
            </div>
            <div style="display:flex;gap:12px;">
                <button class="admin-og-btn" type="submit" data-edit-submit>➕ Add Group</button>
                <button class="admin-og-btn--secondary" type="button" style="display:none;padding:8px 16px;border-radius:6px;border:1px solid var(--cv-border-default);"
                    data-edit-cancel
                    data-edit-reset-action="/admin/configurable-options"
                    data-edit-reset-label="Add Group"
                    data-edit-reset-title="Add Group"
                    data-edit-title-target="#option-group-form-title">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="admin-og-card">
    <div style="padding:24px;border-bottom:1px solid var(--cv-border-default);display:flex;justify-content:space-between;align-items:center;">
        <h2 style="font-family:'Hanken Grotesk',sans-serif;font-weight:800;font-size:1.25rem;color:var(--cv-text-primary);margin:0;">📋 Groups</h2>
        <?php if ($groups !== []): ?>
            <button class="admin-og-btn--danger" type="submit" form="bulk-delete-groups-form" style="padding:6px 12px;font-size:.75rem;border-radius:6px;">🗑️ Delete Selected</button>
        <?php endif; ?>
    </div>
    <div style="overflow-x:auto;">
        <table class="admin-og-table">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;">
                        <input type="checkbox" id="select-all-groups" data-select-all='input[name="selected_groups[]"]'>
                    </th>
                    <th>Name</th>
                    <th style="width:100px;text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $group): ?>
                <?php $groupId = (int) $group['id']; ?>
            <tr>
                <td style="text-align: center; vertical-align: middle;">
                    <input type="checkbox" name="selected_groups[]" value="<?= $groupId ?>" form="bulk-delete-groups-form">
                </td>
                <td style="vertical-align: middle;"><a href="/admin/configurable-options/<?= $groupId ?>"><?= e($group['name']) ?></a></td>
                <td style="display:flex; gap:var(--cv-space-2); justify-content: flex-end; align-items: middle;">
                    <button type="button" class="cv-btn cv-btn--secondary"
                        data-edit-trigger
                        data-edit-form="#option-group-form"
                        data-edit-fields="<?= e(json_encode(['name' => $group['name']])) ?>"
                        data-edit-action="/admin/configurable-options/<?= $groupId ?>/edit"
                        data-edit-submit-label="Update Group"
                        data-edit-title="Edit Group"
                        data-edit-title-target="#option-group-form-title">Edit</button>
                    <form method="post" action="/admin/configurable-options/<?= $groupId ?>/delete" data-confirm="Delete this option group and all of its options?" style="margin:0;">
                        <?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($groups === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary); text-align: center;">No option groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
