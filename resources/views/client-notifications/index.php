<?php
/** @var array<int, array<string, mixed>> $notifications */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Notifications</h1>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:0;">
        Messages from us, including a copy of every email we've sent to your account — so if an email doesn't reach
        you, you'll still see it here. Open one to reply; your reply opens a support ticket.
    </p>
</div>

<div class="cv-card">
    <?php if ($notifications === []): ?>
        <p style="color:var(--cv-text-secondary);">Nothing here yet.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;">
            <?php foreach ($notifications as $notification): ?>
                <a href="/client/notifications/<?= (int) $notification['id'] ?>"
                   style="display:flex;justify-content:space-between;align-items:center;gap:var(--cv-space-3);padding:var(--cv-space-3) 0;border-bottom:1px solid var(--cv-border-default);text-decoration:none;color:inherit;">
                    <div style="min-width:0;">
                        <div style="font-weight:<?= $notification['read_at'] === null ? '800' : '500' ?>;color:var(--cv-text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php if ($notification['read_at'] === null): ?>
                                <span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:var(--cv-color-brand-500);margin-right:8px;"></span>
                            <?php endif; ?>
                            <?= e($notification['subject']) ?>
                        </div>
                        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:2px;">
                            <?= e((string) $notification['created_at']) ?>
                            <?php if (!empty($notification['reply_ticket_id'])): ?>
                                &middot; Replied (Ticket #<?= (int) $notification['reply_ticket_id'] ?>)
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
