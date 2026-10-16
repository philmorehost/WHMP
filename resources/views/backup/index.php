<?php
/** @var array<int, array<string, mixed>> $runs */
$runningCount = count(array_filter($runs, static fn (array $r): bool => ($r['status'] ?? '') === 'running'));
?>
<?php if (isset($_GET['terminated'])): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <?php if ((int) $_GET['terminated'] > 0): ?>
            <div class="cv-alert cv-alert--success">
                Terminated <?= (int) $_GET['terminated'] ?> stuck backup entr<?= (int) $_GET['terminated'] === 1 ? 'y' : 'ies' ?>
                and deleted <?= (int) ($_GET['files'] ?? 0) ?> partial file<?= (int) ($_GET['files'] ?? 0) === 1 ? '' : 's' ?>.
            </div>
        <?php else: ?>
            <div class="cv-alert cv-alert--neutral">No backups were running — nothing to terminate.</div>
        <?php endif; ?>
        <?php if (($_GET['live'] ?? '') === '1'): ?>
            <div class="cv-alert cv-alert--warning" style="margin-top:var(--cv-space-2);">
                A backup process was still executing and cannot be force-killed from the browser —
                its history entry has been removed and it will simply finish on its own.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Backups</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <form method="post" action="/admin/backups" style="display:inline-block;"><?= csrf_field() ?>
        <button class="cv-btn" type="submit">Run Backup Now</button>
    </form>
    <form method="post" action="/admin/backups/terminate" style="display:inline-block;margin-left:var(--cv-space-2);"
          onsubmit="return confirm('Terminate all running backup processes? Stuck entries and their partial files will be deleted.');"><?= csrf_field() ?>
        <button class="cv-btn cv-btn--danger" type="submit">
            Terminate running backups<?= $runningCount > 0 ? ' (' . $runningCount . ')' : '' ?>
        </button>
    </form>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">
        Also runs automatically once a day via cron. A backup dumps the full database (pure PHP, no mysqldump) plus a zip of the application files.
        Terminate closes out stuck "Running" entries (for example after a crashed or piled-up run) and deletes their half-written files — it never touches completed backups.
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
