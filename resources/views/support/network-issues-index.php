<?php
/** @var array<int, array<string, mixed>> $issues */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Network Issues</h1>
    <p><a href="/admin/announcements">Announcements</a> &middot; <a href="/status">View Public Status Page</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <table class="cv-table">
        <thead><tr><th>Title</th><th>Status</th><th>Started</th><th>Resolved</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($issues as $issue): ?>
            <tr>
                <td><?= e($issue['title']) ?></td>
                <td>
                    <?php if ($issue['status'] === 'resolved'): ?>
                        <span class="cv-badge cv-badge--success">Resolved</span>
                    <?php elseif ($issue['status'] === 'investigating'): ?>
                        <span class="cv-badge cv-badge--danger">Investigating</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral"><?= e(ucfirst($issue['status'])) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($issue['started_at']) ?></td>
                <td><?= e((string) ($issue['resolved_at'] ?? '-')) ?></td>
                <td>
                    <form method="post" action="/admin/network-issues/<?= (int) $issue['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            <tr>
                <td colspan="5">
                    <form method="post" action="/admin/network-issues/<?= (int) $issue['id'] ?>/status" style="display:flex;gap:var(--cv-space-2);align-items:end;flex-wrap:wrap;"><?= csrf_field() ?>
                        <div class="cv-field" style="margin-bottom:0;flex:1;">
                            <label class="cv-label">Update Message</label>
                            <input class="cv-input" name="message" value="<?= e($issue['message']) ?>">
                        </div>
                        <div class="cv-field" style="margin-bottom:0;">
                            <label class="cv-label">Status</label>
                            <select class="cv-input" name="status">
                                <option value="investigating" <?= $issue['status'] === 'investigating' ? 'selected' : '' ?>>Investigating</option>
                                <option value="identified" <?= $issue['status'] === 'identified' ? 'selected' : '' ?>>Identified</option>
                                <option value="monitoring" <?= $issue['status'] === 'monitoring' ? 'selected' : '' ?>>Monitoring</option>
                                <option value="resolved" <?= $issue['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>
                        <button class="cv-btn cv-btn--secondary" type="submit">Update</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($issues === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No network issues yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h3>Report New Issue</h3>
    <form method="post" action="/admin/network-issues"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Title</label>
            <input class="cv-input" name="title" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Message</label>
            <textarea class="cv-input" name="message" rows="3" required></textarea>
        </div>
        <button class="cv-btn" type="submit">Report Issue</button>
    </form>
</div>
