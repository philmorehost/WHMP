<?php
/** @var array<string, mixed> $notification */
/** @var array<int, array<string, mixed>> $recipients */

use CodeVault\Support\FormattedText;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($notification['subject']) ?></h1>
    <p><a href="/admin/client-notifications">&larr; Back to notifications</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
        Sent <?= e((string) $notification['created_at']) ?>
        <?php if ($notification['source'] === 'system_email'): ?>
            &middot; <span class="cv-badge cv-badge--neutral">Mirrored system email</span>
        <?php endif; ?>
    </p>
    <div style="border:1px solid var(--cv-border-default);border-radius:8px;padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);">
        <?= FormattedText::toHtml((string) $notification['body']) ?>
    </div>
</div>

<div class="cv-card">
    <h3>Recipients (<?= count($recipients) ?>)</h3>
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Read</th><th>Reply</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $recipient): ?>
            <tr>
                <td><?= e($recipient['first_name'] . ' ' . $recipient['last_name']) ?> (<?= e($recipient['email']) ?>)</td>
                <td>
                    <?php if ($recipient['read_at'] !== null): ?>
                        <span class="cv-badge cv-badge--success">Read <?= e((string) $recipient['read_at']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">Unread</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($recipient['reply_ticket_id'])): ?>
                        <a href="/admin/tickets/<?= (int) $recipient['reply_ticket_id'] ?>">Ticket #<?= (int) $recipient['reply_ticket_id'] ?></a>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">&mdash;</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recipients === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">No recipients.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
