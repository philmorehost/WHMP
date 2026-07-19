<?php
/** @var array<int, array<string, mixed>> $entries */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Email Log</h1>
    <p><a href="/admin/email-templates">&larr; Back to templates</a></p>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Template</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= e($entry['created_at']) ?></td>
                <td><?= e($entry['to_email']) ?></td>
                <td><?= e($entry['subject']) ?></td>
                <td><code><?= e((string) ($entry['template_key'] ?? '-')) ?></code></td>
                <td>
                    <?php if ($entry['status'] === 'sent'): ?>
                        <span class="cv-badge cv-badge--success">Sent</span>
                    <?php elseif ($entry['status'] === 'failed'): ?>
                        <span class="cv-badge cv-badge--danger">Failed</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Queued</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No emails sent yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
