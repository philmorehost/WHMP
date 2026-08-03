<?php
/** @var array<string, mixed> $notification */

use CodeVault\Support\FormattedText;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($notification['subject']) ?></h1>
    <p><a href="/client/notifications">&larr; Back to notifications</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">
        <?= e((string) $notification['created_at']) ?>
    </p>
    <div style="border:1px solid var(--cv-border-default);border-radius:8px;padding:var(--cv-space-3);">
        <?= FormattedText::toHtml((string) $notification['body']) ?>
    </div>
</div>

<div class="cv-card">
    <?php if (!empty($notification['reply_ticket_id'])): ?>
        <p>
            You already replied to this — see
            <a href="/client/tickets/<?= (int) $notification['reply_ticket_id'] ?>">Ticket #<?= (int) $notification['reply_ticket_id'] ?></a>.
        </p>
    <?php else: ?>
        <h3>Reply</h3>
        <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-top:0;">
            Sending this opens a support ticket quoting this message, and our team replies there.
        </p>
        <form method="post" action="/client/notifications/<?= (int) $notification['id'] ?>/reply">
            <?= csrf_field() ?>
            <div class="cv-field">
                <textarea class="cv-input" name="message" rows="5" placeholder="Type your reply…" required></textarea>
            </div>
            <button class="cv-btn" type="submit">Send Reply</button>
        </form>
    <?php endif; ?>
</div>
