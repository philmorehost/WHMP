<?php
/** @var array<int, array<string, mixed>> $cannedReplies */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Canned Replies</h1>
    <p><a href="/admin/tickets">&larr; Back to tickets</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Title</th><th>Body</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cannedReplies as $canned): ?>
            <tr>
                <td><?= e($canned['title']) ?></td>
                <td><?= e(mb_strimwidth((string) $canned['body'], 0, 80, '...')) ?></td>
                <td>
                    <form method="post" action="/admin/canned-replies/<?= (int) $canned['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($cannedReplies === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No canned replies yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Canned Reply</h3>
    <form method="post" action="/admin/canned-replies"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Title</label>
            <input class="cv-input" name="title" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body</label>
            <textarea class="cv-input" name="body" rows="4" required></textarea>
        </div>
        <button class="cv-btn" type="submit">Add</button>
    </form>
</div>
