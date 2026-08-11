<?php
/** @var array<int, array<string, mixed>> $emails */
/** @var array{total: int, page: int, perPage: int, pages: int} $pagination */
/** @var string|null $error */
$statusBadge = static function (string $status): string {
    return match ($status) {
        'sent' => '<span class="cv-badge cv-badge--success">Sent</span>',
        'queued' => '<span class="cv-badge cv-badge--neutral">Queued</span>',
        'failed' => '<span class="cv-badge cv-badge--danger">Failed</span>',
        default => '<span class="cv-badge cv-badge--neutral">' . e($status) . '</span>',
    };
};
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">My Emails</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>
    <p style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm); margin-bottom:0;">
        A record of every email we've sent to you — invoice reminders, renewal notices, ticket replies, and announcements. If something looks missing or a message shows as failed, contact support.
    </p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-badge cv-badge--danger" style="display:block; padding:var(--cv-space-3); margin-bottom:var(--cv-space-4);">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="cv-card">
    <?php if ($emails === []): ?>
        <p style="color:var(--cv-text-secondary);">
            You have no emails on record yet. Emails appear here after the first invoice, renewal reminder, or ticket reply is sent to you.
        </p>
    <?php else: ?>
        <table class="cv-table">
            <thead><tr><th>Subject</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($emails as $email): ?>
                <tr>
                    <td>
                        <strong><?= e((string) ($email['subject'] ?: '(no subject)')) ?></strong>
                        <br><span style="color:var(--cv-text-secondary); font-size:var(--cv-text-xs);">
                            <?= e((string) ($email['template_key'] ?: 'Email')) ?>
                        </span>
                    </td>
                    <td><?= $statusBadge((string) $email['status']) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($email['created_at'])): ?>
                            <?= e(date('M j, Y g:i A', strtotime((string) $email['created_at']))) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pagination['pages'] > 1): ?>
            <nav style="display:flex; gap:var(--cv-space-2); justify-content:center; margin-top:var(--cv-space-4);" aria-label="Email log pages">
                <?php if ($pagination['page'] > 1): ?>
                    <a class="cv-btn cv-btn--secondary" href="/client/emails?page=<?= $pagination['page'] - 1 ?>">&larr; Prev</a>
                <?php endif; ?>
                <span style="align-self:center; color:var(--cv-text-secondary); font-size:var(--cv-text-sm);">
                    Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?>
                </span>
                <?php if ($pagination['page'] < $pagination['pages']): ?>
                    <a class="cv-btn cv-btn--secondary" href="/client/emails?page=<?= $pagination['page'] + 1 ?>">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
