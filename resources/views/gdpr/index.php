<?php
/** @var array<int, array<string, mixed>> $requests */
/** @var array{activityLogDays: int, loginAttemptsDays: int, emailLogDays: int} $retention */
/** @var string|null $error */
$badgeClass = static fn (string $status): string => match ($status) {
    'completed' => 'cv-badge--success',
    'rejected' => 'cv-badge--danger',
    default => 'cv-badge--neutral',
};
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">GDPR / Privacy Requests</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Type</th><th>Status</th><th>Requested</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= e((string) $r['client_email']) ?></td>
                <td><?= e(ucfirst((string) $r['type'])) ?></td>
                <td><span class="cv-badge <?= $badgeClass((string) $r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                <td><?= e((string) $r['created_at']) ?></td>
                <td><?= e((string) ($r['admin_notes'] ?? '')) ?></td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                        <?php $confirmMessage = $r['type'] === 'erasure' ? "Process this erasure? The client's personal data will be scrubbed and their account closed — this cannot be undone." : 'Generate this data export?'; ?>
                        <form method="post" action="/admin/gdpr/<?= (int) $r['id'] ?>/process" style="display:inline;" data-confirm="<?= e($confirmMessage) ?>"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Process</button>
                        </form>
                        <form method="post" action="/admin/gdpr/<?= (int) $r['id'] ?>/reject" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--secondary" type="submit">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($requests === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No requests yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Retention Periods</h2>
    <p style="color:var(--cv-text-secondary);">How long the daily pruning job keeps these logs before deleting rows older than the threshold.</p>
    <form method="post" action="/admin/gdpr/settings"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Activity log (days)</label>
            <input class="cv-input" type="number" min="1" name="activity_log_days" value="<?= (int) $retention['activityLogDays'] ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Login attempts (days)</label>
            <input class="cv-input" type="number" min="1" name="login_attempts_days" value="<?= (int) $retention['loginAttemptsDays'] ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Email log (days)</label>
            <input class="cv-input" type="number" min="1" name="email_log_days" value="<?= (int) $retention['emailLogDays'] ?>">
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>
</div>
