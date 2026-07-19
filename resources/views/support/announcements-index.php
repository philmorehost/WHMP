<?php
/** @var array<int, array<string, mixed>> $announcements */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Announcements</h1>
    <p><a href="/admin/network-issues">Network Issues</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Title</th><th>Published</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($announcements as $announcement): ?>
            <tr>
                <td><?= e($announcement['title']) ?></td>
                <td><?= e($announcement['published_at']) ?></td>
                <td>
                    <form method="post" action="/admin/announcements/<?= (int) $announcement['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($announcements === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No announcements yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Announcement</h3>
    <form method="post" action="/admin/announcements"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Title</label>
            <input class="cv-input" name="title" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body</label>
            <textarea class="cv-input" name="body" rows="4" required></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">Publish At (blank = now)</label>
            <input class="cv-input" type="datetime-local" name="published_at">
        </div>
        <button class="cv-btn" type="submit">Publish</button>
    </form>
</div>
