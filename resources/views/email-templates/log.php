<?php
/** @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results */
/** @var string $search */
$totalPages = max(1, (int) ceil($results['total'] / $results['perPage']));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Email History</h1>
    <p><a href="/admin/email-templates">&larr; Back to templates</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <form method="get" action="/admin/email-log" style="display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
        <input class="cv-input" type="text" name="q" value="<?= e($search) ?>" placeholder="Search by subject or recipient…" style="flex:1;min-width:220px;">
        <button class="cv-btn" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="cv-btn cv-btn--secondary" href="/admin/email-log">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="cv-card">
    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:-4px;margin-bottom:var(--cv-space-2);">
        A marketing campaign sent to many recipients appears as a single row here — open it to see who it went to and
        who it failed for. Every other email (invoice reminders, ticket replies, one-off admin messages) is its own row.
    </p>
    <table class="cv-table">
        <thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($results['data'] as $entry): ?>
            <tr>
                <td style="white-space:nowrap;"><?= e((string) $entry['created_at']) ?></td>
                <td>
                    <?php if ($entry['kind'] === 'campaign'): ?>
                        <a href="/admin/campaigns/<?= (int) $entry['campaign_id'] ?>"><?= e((string) $entry['audience_label']) ?> (<?= (int) $entry['recipient_count'] ?>)</a>
                    <?php else: ?>
                        <?= e((string) $entry['to_email']) ?>
                    <?php endif; ?>
                </td>
                <td><?= e((string) $entry['subject']) ?></td>
                <td>
                    <?php if ($entry['kind'] === 'campaign'): ?>
                        <span class="cv-badge cv-badge--neutral">Campaign</span>
                    <?php elseif (!empty($entry['template_key'])): ?>
                        <code><?= e((string) $entry['template_key']) ?></code>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($entry['kind'] === 'campaign'): ?>
                        <span class="cv-badge cv-badge--success"><?= (int) $entry['sent_count'] ?> sent</span>
                        <?php if ((int) $entry['failed_count'] > 0): ?>
                            <span class="cv-badge cv-badge--danger"><?= (int) $entry['failed_count'] ?> failed</span>
                        <?php endif; ?>
                        <?php if ((int) $entry['queued_count'] > 0): ?>
                            <span class="cv-badge cv-badge--neutral"><?= (int) $entry['queued_count'] ?> queued</span>
                        <?php endif; ?>
                    <?php elseif ($entry['status'] === 'sent'): ?>
                        <span class="cv-badge cv-badge--success">Sent</span>
                    <?php elseif ($entry['status'] === 'failed'): ?>
                        <span class="cv-badge cv-badge--danger">Failed</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Queued</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($results['data'] === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);"><?= $search !== '' ? 'No emails match that search.' : 'No emails sent yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($results['total'] > 0): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--cv-space-2);margin-top:var(--cv-space-3);">
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                Page <?= (int) $results['page'] ?> of <?= $totalPages ?> (<?= number_format($results['total']) ?> total)
            </span>
            <div style="display:flex;gap:var(--cv-space-2);">
                <?php if ($results['page'] > 1): ?>
                    <a class="cv-btn cv-btn--secondary" href="/admin/email-log?q=<?= urlencode($search) ?>&page=<?= $results['page'] - 1 ?>">&larr; Previous</a>
                <?php endif; ?>
                <?php if ($results['page'] < $totalPages): ?>
                    <a class="cv-btn cv-btn--secondary" href="/admin/email-log?q=<?= urlencode($search) ?>&page=<?= $results['page'] + 1 ?>">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
