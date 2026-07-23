<?php
/** @var array<int, array<string, mixed>> $cannedReplies */
?>
<style>
.admin-cr-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-cr-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-cr-hero__back {
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
.admin-cr-hero__title {
    position: relative;
    z-index: 1;
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
}
.admin-cr-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-cr-card__title {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--cv-text-primary);
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-cr-card__body {
    padding: 24px;
}
.admin-cr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-cr-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-cr-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
}
.admin-cr-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-cr-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-cr-btn-delete {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 600;
    font-size: .75rem;
    cursor: pointer;
    transition: all 0.2s;
}
.admin-cr-btn-delete:hover {
    background: rgba(239,68,68,.3);
}
.admin-cr-field {
    margin-bottom: 16px;
}
.admin-cr-field label {
    display: block;
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.admin-cr-field input,
.admin-cr-field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
    box-sizing: border-box;
}
.admin-cr-field input:focus,
.admin-cr-field textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-cr-btn-save {
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
.admin-cr-btn-save:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
}
</style>

<div class="admin-cr-hero">
    <a href="/admin/tickets" class="admin-cr-hero__back">← Back to Tickets</a>
    <h1 class="admin-cr-hero__title">💬 Canned Replies</h1>
</div>

<div class="admin-cr-card">
    <h2 class="admin-cr-card__title">📋 Existing Replies</h2>
    <div class="admin-cr-card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="admin-cr-table">
                <thead><tr><th>Title</th><th>Body</th><th style="width:70px;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($cannedReplies as $canned): ?>
                    <tr>
                        <td><strong><?= e($canned['title']) ?></strong></td>
                        <td><?= e(mb_strimwidth((string) $canned['body'], 0, 80, '...')) ?></td>
                        <td>
                            <form method="post" action="/admin/canned-replies/<?= (int) $canned['id'] ?>/delete" style="margin:0;">
                                <?= csrf_field() ?>
                                <button class="admin-cr-btn-delete" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($cannedReplies === []): ?>
                    <tr><td colspan="3" style="color:var(--cv-text-secondary);text-align:center;padding:32px;">No canned replies yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="admin-cr-card">
    <h2 class="admin-cr-card__title">➕ Add New Reply</h2>
    <div class="admin-cr-card__body">
        <form method="post" action="/admin/canned-replies"><?= csrf_field() ?>
            <div class="admin-cr-field">
                <label>Title</label>
                <input name="title" required>
            </div>
            <div class="admin-cr-field">
                <label>Body</label>
                <textarea name="body" rows="4" required></textarea>
            </div>
            <button class="admin-cr-btn-save" type="submit">💾 Add Reply</button>
        </form>
    </div>
</div>
