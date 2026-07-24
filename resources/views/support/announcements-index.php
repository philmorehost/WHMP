<?php
/** @var array<int, array<string, mixed>> $announcements */
?>
<style>
.admin-ann-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-ann-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-ann-hero__back {
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
.admin-ann-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-ann-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.admin-ann-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-ann-card__body {
    padding: 24px;
}
.admin-ann-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-ann-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-ann-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-ann-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-ann-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-ann-btn {
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
.admin-ann-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.admin-ann-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
    padding: 6px 12px;
    font-size: .75rem;
}
.admin-ann-btn--danger:hover {
    background: rgba(239,68,68,.3);
}
.admin-ann-field {
    margin-bottom: 16px;
}
.admin-ann-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    margin-bottom: 6px;
}
.admin-ann-field input,
.admin-ann-field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    box-sizing: border-box;
    font-family: inherit;
}
.admin-ann-field input:focus,
.admin-ann-field textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
</style>

<div class="admin-ann-hero">
    <h1 class="admin-ann-hero__title">📢 Announcements</h1>
    <a href="/admin/network-issues" style="position:relative;z-index:1;display:inline-flex;align-items:center;gap:8px;color:#3b82f6;text-decoration:none;font-weight:600;font-size:.9rem;margin-top:12px;">
        <span>→</span>
        <span>Network Issues</span>
    </a>
</div>

<div class="admin-ann-card">
    <h2 class="admin-ann-card__title">📋 Existing Announcements</h2>
    <div class="admin-ann-card__body" style="padding:0;overflow-x:auto;">
        <table class="admin-ann-table">
            <thead><tr><th>Title</th><th>Published</th><th style="width:80px;">Action</th></tr></thead>
            <tbody>
            <?php foreach ($announcements as $announcement): ?>
                <tr>
                    <td><strong><?= e($announcement['title']) ?></strong></td>
                    <td style="font-size:.85rem;color:var(--cv-text-secondary);"><?= e($announcement['published_at']) ?></td>
                    <td>
                        <form method="post" action="/admin/announcements/<?= (int) $announcement['id'] ?>/delete" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="admin-ann-btn--danger" type="submit">🗑️ Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($announcements === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No announcements yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-ann-card">
    <h2 class="admin-ann-card__title">➕ Add Announcement</h2>
    <div class="admin-ann-card__body">
        <form method="post" action="/admin/announcements"><?= csrf_field() ?>
            <div class="admin-ann-field">
                <label>Title</label>
                <input name="title" required>
            </div>
            <div class="admin-ann-field">
                <label>Body</label>
                <textarea name="body" rows="4" required></textarea>
            </div>
            <div class="admin-ann-field">
                <label>Publish At (blank = now)</label>
                <input type="datetime-local" name="published_at">
            </div>
            <button class="admin-ann-btn" type="submit">📢 Publish Announcement</button>
        </form>
    </div>
</div>
