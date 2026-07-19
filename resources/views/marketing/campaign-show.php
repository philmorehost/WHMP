<?php
/** @var array<string, mixed> $campaign */
/** @var array<int, array<string, mixed>> $recipients */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($campaign['subject']) ?></h1>
    <p><a href="/admin/campaigns">&larr; Back to campaigns</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p><strong>Status:</strong> <?= e(ucfirst($campaign['status'])) ?></p>
    <?php if ($campaign['status'] === 'draft'): ?>
        <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/send" data-confirm="Send this campaign now?"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Send Now</button>
        </form>
    <?php endif; ?>
    <h3>Preview</h3>
    <div class="cv-card" style="background:var(--cv-color-brand-50);">
        <?= $campaign['body'] ?>
    </div>
</div>

<div class="cv-card">
    <h3>Recipients (<?= count($recipients) ?>)</h3>
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Sent</th><th>Opened</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $recipient): ?>
            <tr>
                <td><?= e($recipient['first_name'] . ' ' . $recipient['last_name']) ?> (<?= e($recipient['email']) ?>)</td>
                <td><?= e((string) $recipient['sent_at']) ?></td>
                <td>
                    <?php if ($recipient['opened_at'] !== null): ?>
                        <span class="cv-badge cv-badge--success">Opened <?= e((string) $recipient['opened_at']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">Not opened</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recipients === []): ?>
            <tr><td colspan="3" style="color:var(--cv-text-secondary);">Not sent yet — no recipients recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
