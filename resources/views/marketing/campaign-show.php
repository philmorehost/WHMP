<?php
/** @var array<string, mixed> $campaign */
/** @var array<int, array<string, mixed>> $recipients */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($campaign['subject']) ?></h1>
    <p><a href="/admin/campaigns">&larr; Back to campaigns</a></p>
</div>

<?php
// Feedback for the pause/resume/edit actions below — each redirects back
// here with one of these query params rather than a session flash, matching
// how queuedCount already works on this page.
$feedback = [
    'paused' => ['ok', 'Campaign paused. The cron will not send it any further until you resume it.'],
    'resumed' => ['ok', 'Campaign resumed — the cron will continue sending to the remaining recipients.'],
    'updated' => ['ok', 'Campaign updated. Recipients still pending will get this version when sending resumes.'],
    'pause_error' => ['error', 'Could not pause — it may not be sending right now.'],
    'resume_error' => ['error', 'Could not resume — it may not be paused right now.'],
];
?>
<?php foreach ($feedback as $param => [$kind, $text]): ?>
    <?php if (($_GET[$param] ?? '') !== ''): ?>
        <div class="cv-alert cv-alert--<?= $kind === 'ok' ? 'success' : 'danger' ?>" style="margin-bottom:var(--cv-space-3);"><?= e($text) ?></div>
    <?php endif; ?>
<?php endforeach; ?>
<?php if (($_GET['edit_error'] ?? '') !== ''): ?>
    <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-3);"><?= e((string) $_GET['edit_error']) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p><strong>Status:</strong> <?= e(ucfirst($campaign['status'])) ?></p>
    <p>
        <strong>Audience:</strong>
        <?php if (!empty($campaign['client_id'])): ?>
            👤 Individual client
        <?php elseif (!empty($campaign['only_inactive'])): ?>
            📭 Accounts with no active product/domain
        <?php elseif (!empty($campaign['client_group_id'])): ?>
            📁 Client group
        <?php else: ?>
            🌐 All active clients
        <?php endif; ?>
    </p>

    <?php
    // Sending is now queued and drained by the cron, so the admin needs to see
    // progress rather than a page that looks stuck.
    $totalRecipients = count($recipients);
    $sentCount = 0;
    $failedCount = 0;
    foreach ($recipients as $r) {
        // A recipient with send_error set has failed even if sent_at is also
        // set — sent_at means "attempted", not "delivered"; the real outcome
        // is only known once email_log confirms it, and send_error is where
        // that confirmation lands. See MailCampaignRepository::syncConfirmedFailures().
        if (!empty($r['send_error'])) {
            $failedCount++;
        } elseif (!empty($r['sent_at'])) {
            $sentCount++;
        }
    }
    $processed = $sentCount + $failedCount;
    $remaining = max(0, $totalRecipients - $processed);
    $percent = $totalRecipients > 0 ? (int) round($processed / $totalRecipients * 100) : 0;
    ?>

    <?php if ($campaign['status'] === 'sending'): ?>
        <div style="border:1px solid var(--cv-border-default);border-radius:10px;padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);margin-bottom:var(--cv-space-3);">
            <strong>Sending in the background</strong>
            <div style="height:10px;background:var(--cv-border-default);border-radius:99px;overflow:hidden;margin:10px 0;">
                <div style="height:100%;width:<?= $percent ?>%;background:var(--cv-color-brand-500);"></div>
            </div>
            <div style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                <?= (int) $sentCount ?> of <?= (int) $totalRecipients ?> sent (<?= $percent ?>%) &nbsp;&bull;&nbsp;
                <?= (int) $remaining ?> remaining. The cron sends a batch each minute — you can safely leave this page.
            </div>
            <?php if ($failedCount > 0): ?>
                <div style="font-size:var(--cv-text-sm);color:#ef4444;margin-top:6px;">
                    ⚠️ <?= (int) $failedCount ?> recipient(s) failed to send — see the Sent column below for the reason.
                    The campaign auto-pauses once 5 failures are confirmed.
                </div>
            <?php endif; ?>
            <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/pause"
                  data-confirm="Pause this campaign? Nobody still pending will be sent to until you resume it."
                  style="margin-top:var(--cv-space-2);">
                <?= csrf_field() ?>
                <button class="cv-btn cv-btn--secondary" type="submit">⏸️ Pause Sending</button>
            </form>
        </div>
    <?php elseif ($campaign['status'] === 'paused'): ?>
        <div style="border:1px solid rgba(245,158,11,.35);border-radius:10px;padding:var(--cv-space-3);background:rgba(245,158,11,0.08);margin-bottom:var(--cv-space-3);">
            <strong>⏸️ Paused</strong> — <?= (int) $sentCount ?> of <?= (int) $totalRecipients ?> already sent, <?= (int) $remaining ?> still waiting.
            <div style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-top:4px;">
                Review the subject/body below, edit if needed, then resume — the remaining recipients get whatever
                version is saved at that point. Anyone already sent to keeps what they got; they are not re-sent.
            </div>
            <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/resume" style="margin-top:var(--cv-space-2);">
                <?= csrf_field() ?>
                <button class="cv-btn" type="submit">▶️ Resume Sending</button>
            </form>
        </div>

        <details style="margin-bottom:var(--cv-space-3);">
            <summary style="cursor:pointer;font-weight:600;">✏️ Edit subject/body</summary>
            <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/update" style="margin-top:var(--cv-space-2);">
                <?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Subject</label>
                    <input class="cv-input" name="subject" value="<?= e((string) $campaign['subject']) ?>" required>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Body (HTML)</label>
                    <textarea class="cv-input" name="body" rows="8" required><?= e((string) $campaign['body']) ?></textarea>
                    <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                        <code>[Client Name]</code> or <code>{{client_name}}</code> is replaced with each recipient's real name when sent.
                    </div>
                </div>
                <button class="cv-btn" type="submit">Save Changes</button>
            </form>
        </details>
    <?php elseif ($campaign['status'] === 'draft'): ?>
        <?php if (($queuedCount ?? null) !== null): ?>
            <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
                <?= (int) $queuedCount ?> recipient(s) queued.
            </div>
        <?php endif; ?>
        <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/send" data-confirm="Queue this campaign for sending?"><?= csrf_field() ?>
            <button class="cv-btn" type="submit">Send Now</button>
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-left:var(--cv-space-2);">
                Queues the campaign — the cron then sends it in small batches so the mail server isn't overloaded.
            </span>
        </form>
    <?php elseif ($campaign['status'] === 'sent'): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            Delivered to <?= (int) $sentCount ?> recipient(s).
        </p>
    <?php endif; ?>
    <h3>Preview</h3>
    <?php
    // Run the body through the same normaliser the mailer uses, so a body
    // typed as plain prose previews with the paragraphs it will actually be
    // sent with — previously this printed the raw value, which collapsed
    // every line break and showed one unbroken wall of text.
    ?>
    <div style="max-width:640px;margin:0 auto;border:1px solid var(--cv-border-default);border-radius:12px;overflow:hidden;background:#ffffff;">
        <div style="background:#0f172a;padding:18px 24px;text-align:center;color:#ffffff;font-weight:700;font-size:1rem;">
            <?= e($brandName ?? 'Email preview') ?>
        </div>
        <div style="height:4px;background:var(--cv-color-brand-500);"></div>
        <div style="padding:24px;color:#334155;font-size:15px;line-height:1.65;background:#ffffff;">
            <?= CodeVault\Mail\EmailContent::toHtml((string) $campaign['body']) ?>
        </div>
        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 24px;text-align:center;font-size:12px;color:#64748b;">
            This is how the message body will appear to recipients. <code>[Client Name]</code> becomes each recipient's real name when sent.
        </div>
    </div>
</div>

<div class="cv-card">
    <h3>Recipients (<?= count($recipients) ?>)</h3>
    <?php
    // "Opened" can only ever be a lower bound: it depends on the recipient's
    // mail client fetching a 1x1 tracking image, and most clients (Outlook,
    // many Apple Mail / Gmail configurations) block remote images until the
    // recipient explicitly allows them. A client who genuinely opened the
    // email and never clicked "show images" will always read "Not opened"
    // here — that is not a bug in this page, it is what a pixel can and
    // cannot see, and the label below says so rather than implying a
    // definite yes/no the mechanism cannot actually deliver.
    ?>
    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:-8px;margin-bottom:var(--cv-space-2);">
        "Opened" is recorded only if the recipient's email client loads remote images — many clients block this by
        default until the recipient allows it. Treat these counts as a minimum, not an exact figure.
    </p>
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Sent</th><th>Opened</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $recipient): ?>
            <tr>
                <td><?= e($recipient['first_name'] . ' ' . $recipient['last_name']) ?> (<?= e($recipient['email']) ?>)</td>
                <td>
                    <?php if (!empty($recipient['send_error'])): ?>
                        <span style="color:#ef4444;font-weight:600;">Failed</span>
                        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);display:block;" title="<?= e((string) $recipient['send_error']) ?>">
                            <?= e((string) $recipient['send_error']) ?><?= empty($recipient['sent_at']) ? ' — will retry' : '' ?>
                        </span>
                    <?php elseif (!empty($recipient['sent_at'])): ?>
                        <?= e((string) $recipient['sent_at']) ?>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">Pending</span>
                    <?php endif; ?>
                </td>
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
