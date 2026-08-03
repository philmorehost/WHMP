<?php
/** @var array<int, array<string, mixed>> $departments */
?>
<style>
.admin-dept-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-dept-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-dept-hero__back {
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
.admin-dept-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-dept-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-dept-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-dept-card__body {
    padding: 24px;
}
.admin-dept-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-dept-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-dept-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-dept-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-dept-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-dept-btn {
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
.admin-dept-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
}
.admin-dept-btn--secondary {
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    border: 1px solid var(--cv-border-default);
}
.admin-dept-btn--secondary:hover {
    background: var(--cv-bg-surface-sunken);
    border-color: var(--cv-color-brand-500);
}
.admin-dept-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-dept-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-dept-field {
    margin-bottom: 16px;
}
.admin-dept-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.admin-dept-field input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    box-sizing: border-box;
}
.admin-dept-field input:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-dept-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: end;
}
.admin-dept-form button {
    height: fit-content;
}
@media (max-width: 768px) {
    .admin-dept-hero {
        padding: 32px 24px;
    }
    .admin-dept-hero__title {
        font-size: 1.5rem;
    }
    .admin-dept-form {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="admin-dept-hero">
    <a href="/admin/tickets" class="admin-dept-hero__back">← Back to Tickets</a>
    <h1 class="admin-dept-hero__title">📂 Departments</h1>
</div>

<?php if (($error ?? null) !== null && $error !== ''): ?>
    <div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#b91c1c;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;">
        ⚠️ <?= e((string) $error) ?>
    </div>
<?php endif; ?>

<?php if (($purged ?? null) !== null): ?>
    <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#047857;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;">
        🧹 Removed <?= number_format((int) $purged) ?> ticket(s) and <?= number_format((int) ($purgedFiles ?? 0)) ?> attachment file(s).
        <?php if ((int) ($purgeRemaining ?? 0) > 0): ?>
            <br><span style="font-weight:500;">
                <?= number_format((int) $purgeRemaining) ?> still match that clean-up — the batch stopped early to stay inside the server's request limit. Run it again to continue.
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($deleted)): ?>
    <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#047857;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;">
        ✅ Department deleted<?= ($movedCount ?? 0) > 0 ? ' — ' . number_format((int) $movedCount) . ' ticket(s) moved.' : '.' ?>
    </div>
<?php endif; ?>

<div class="admin-dept-card">
    <h2 class="admin-dept-card__title">📋 Existing Departments</h2>
    <div class="admin-dept-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-dept-table">
                <thead><tr><th>Name</th><th>Email (IMAP)</th><th style="width:90px;">Tickets</th><th style="width:260px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $department): ?>
                    <tr>
                        <td><strong><?= e($department['name']) ?></strong></td>
                        <td><code style="background:var(--cv-bg-surface-sunken);padding:2px 6px;border-radius:4px;font-size:.85rem;"><?= e((string) ($department['email'] ?? '-')) ?></code></td>
                        <td><?= number_format((int) $department['ticket_count']) ?></td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="admin-dept-btn--secondary" style="padding:6px 12px;font-size:.75rem;border-radius:6px;border:1px solid var(--cv-border-default);"
                                data-edit-trigger
                                data-edit-form="#department-form"
                                data-edit-fields="<?= e(json_encode(['name' => $department['name'], 'email' => (string) ($department['email'] ?? '')])) ?>"
                                data-edit-action="/admin/departments/<?= (int) $department['id'] ?>"
                                data-edit-submit-label="Update"
                                data-edit-title="Edit Department"
                                data-edit-title-target="#department-form-title">✏️ Edit</button>
                            <?php
                            // Tickets cannot be orphaned — department_id is NOT NULL with a
                            // RESTRICT foreign key. So a department holding tickets offers a
                            // destination to move them to; deleting without one previously
                            // produced a raw database fatal error.
                            $others = array_values(array_filter(
                                $departments,
                                static fn (array $d): bool => (int) $d['id'] !== (int) $department['id']
                            ));
                            ?>
                            <form method="post" action="/admin/departments/<?= (int) $department['id'] ?>/delete" style="margin:0;display:flex;gap:6px;align-items:center;"
                                  data-confirm="<?= (int) $department['ticket_count'] > 0
                                      ? 'Move ' . (int) $department['ticket_count'] . ' ticket(s) to the selected department and delete this one?'
                                      : 'Delete this department?' ?>">
                                <?= csrf_field() ?>
                                <?php if ((int) $department['ticket_count'] > 0): ?>
                                    <?php if ($others === []): ?>
                                        <span style="font-size:.75rem;color:var(--cv-text-secondary);">Create another department first</span>
                                    <?php else: ?>
                                        <select name="move_to" class="cv-select" style="padding:5px 8px;font-size:.75rem;" aria-label="Move tickets to">
                                            <?php foreach ($others as $other): ?>
                                                <option value="<?= (int) $other['id'] ?>">move to: <?= e($other['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="admin-dept-btn--danger" style="padding:6px 12px;font-size:.75rem;border-radius:6px;" type="submit">🗑️ Delete</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button class="admin-dept-btn--danger" style="padding:6px 12px;font-size:.75rem;border-radius:6px;" type="submit">🗑️ Delete</button>
                                <?php endif; ?>
                            </form>
                            <?php if ((int) $department['ticket_count'] > 0): ?>
                                <form method="post" action="/admin/departments/<?= (int) $department['id'] ?>/empty" style="margin:0;display:flex;gap:6px;align-items:center;"
                                      data-confirm="Permanently delete the matching tickets in &quot;<?= e($department['name']) ?>&quot;, including their replies and attachments? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <select name="scope" class="cv-select" style="padding:5px 8px;font-size:.75rem;" aria-label="What to clear">
                                        <?php foreach (($purgeScopes ?? []) as $key => $label): ?>
                                            <option value="<?= e((string) $key) ?>">clear: <?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="admin-dept-btn--danger" style="padding:6px 12px;font-size:.75rem;border-radius:6px;" type="submit">🧹 Empty</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($departments === []): ?>
                    <tr><td colspan="4" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No departments yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="admin-dept-card">
    <h2 class="admin-dept-card__title" id="department-form-title">➕ Add Department</h2>
    <div class="admin-dept-card__body">
        <form id="department-form" method="post" action="/admin/departments" class="admin-dept-form"><?= csrf_field() ?>
            <div class="admin-dept-field">
                <label>Department Name</label>
                <input name="name" id="department-name" required>
            </div>
            <div class="admin-dept-field">
                <label>Email (optional, for IMAP piping)</label>
                <input type="email" name="email" id="department-email">
            </div>
            <div style="display:flex;gap:12px;">
                <button class="admin-dept-btn" type="submit" data-edit-submit>➕ Add Department</button>
                <button class="admin-dept-btn--secondary" type="button" style="display:none;padding:8px 16px;border-radius:6px;border:1px solid var(--cv-border-default);"
                    data-edit-cancel
                    data-edit-reset-action="/admin/departments"
                    data-edit-reset-label="Add"
                    data-edit-reset-title="Add Department"
                    data-edit-title-target="#department-form-title">Cancel</button>
            </div>
        </form>
    </div>
</div>
