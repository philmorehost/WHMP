<?php
/** @var array<int, array<string, mixed>> $activeIssues */
/** @var array<int, array<string, mixed>> $resolvedIssues */
/** @var array<int, array<string, mixed>> $announcements */
?>
<div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">System Status</h1>
    <?php if ($activeIssues === []): ?>
        <p><span class="cv-badge cv-badge--success">All Systems Operational</span></p>
    <?php else: ?>
        <p><span class="cv-badge cv-badge--danger">Active Issues</span></p>
    <?php endif; ?>
</div>

<?php if ($activeIssues !== []): ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Active Issues</h2>
        <?php foreach ($activeIssues as $issue): ?>
            <div style="border-bottom:1px solid var(--cv-border);padding:var(--cv-space-3) 0;">
                <p><strong><?= e($issue['title']) ?></strong> — <span class="cv-badge cv-badge--neutral"><?= e(ucfirst($issue['status'])) ?></span></p>
                <p><?= e($issue['message']) ?></p>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Started <?= e($issue['started_at']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($announcements !== []): ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Announcements</h2>
        <?php foreach ($announcements as $announcement): ?>
            <div style="border-bottom:1px solid var(--cv-border);padding:var(--cv-space-3) 0;">
                <p><strong><?= e($announcement['title']) ?></strong></p>
                <p><?= e($announcement['body']) ?></p>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e($announcement['published_at']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($resolvedIssues !== []): ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;">
        <h2 class="cv-card__title">Recent History</h2>
        <?php foreach ($resolvedIssues as $issue): ?>
            <div style="border-bottom:1px solid var(--cv-border);padding:var(--cv-space-3) 0;">
                <p><strong><?= e($issue['title']) ?></strong> — <span class="cv-badge cv-badge--success">Resolved</span></p>
                <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Resolved <?= e((string) $issue['resolved_at']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
