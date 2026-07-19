<?php
/** @var array<int, array<string, mixed>> $runs */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Backups</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <form method="post" action="/admin/backups"><?= csrf_field() ?>
        <button class="cv-btn" type="submit">Run Backup Now</button>
    </form>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
        Also runs automatically once a day via cron. A backup dumps the full database (pure PHP, no mysqldump) plus a zip of the application files.
    </p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Started</th><th>Status</th><th>Size</th><th>Finished</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $run): ?>
            <tr>
                <td><?= e($run['started_at']) ?></td>
                <td>
                    <?php if ($run['status'] === 'success'): ?>
                        <span class="cv-badge cv-badge--success">Success</span>
                    <?php elseif ($run['status'] === 'failed'): ?>
                        <span class="cv-badge cv-badge--danger">Failed<?php if (!empty($run['error'])): ?> — <?= e($run['error']) ?><?php endif; ?></span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Running</span>
                    <?php endif; ?>
                </td>
                <td><?= $run['size_bytes'] !== null ? number_format((int) $run['size_bytes'] / 1024 / 1024, 2) . ' MB' : '—' ?></td>
                <td><?= e((string) ($run['finished_at'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($runs === []): ?>
            <tr><td colspan="4" style="color:var(--cv-text-secondary);">No backups run yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
