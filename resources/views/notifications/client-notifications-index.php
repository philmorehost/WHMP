<?php
/** @var array<int, array<string, mixed>> $sent */
/** @var array<int, array<string, mixed>> $clients */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Client Notifications</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if (($_GET['sent'] ?? '') !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">Notification sent.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">New Notification</h2>
    <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-top:0;">
        Appears in the recipient's notification center the moment they next log in — independent of email, so it
        still reaches a client whose registered address is broken or unreachable. They can reply to it, which opens
        a support ticket quoting your message.
    </p>
    <form method="post" action="/admin/client-notifications"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Message</label>
            <textarea class="cv-input" name="body" rows="6" required></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">Send To</label>
            <select class="cv-select" id="client-notification-target-type" name="target">
                <option value="individual">Individual Client</option>
                <option value="selected">Selected Clients</option>
                <option value="all">All Active Clients</option>
            </select>
        </div>

        <div class="cv-field" id="notif-target-individual-field">
            <label class="cv-label">Client</label>
            <select class="cv-select" name="client_id">
                <option value="">— Select Client —</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>"><?= e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="cv-field" id="notif-target-selected-field" style="display:none;">
            <label class="cv-label">Clients</label>
            <input class="cv-input" type="text" data-notif-client-filter placeholder="Filter by name or email…" style="margin-bottom:var(--cv-space-2);">
            <div style="max-height:16rem;overflow-y:auto;border:1px solid var(--cv-border-default);border-radius:8px;padding:var(--cv-space-2);">
                <?php foreach ($clients as $client): ?>
                    <label data-notif-client-row style="display:block;padding:4px 0;cursor:pointer;">
                        <input type="checkbox" name="client_ids[]" value="<?= (int) $client['id'] ?>">
                        <span data-notif-client-label><?= e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($clients === []): ?>
                    <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No clients yet.</span>
                <?php endif; ?>
            </div>
        </div>

        <button class="cv-btn" type="submit">Send Notification</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Sent</h2>
    <table class="cv-table">
        <thead><tr><th>Subject</th><th>Sent By</th><th>Recipients</th><th>Read</th><th>Time</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sent as $notification): ?>
            <tr>
                <td><?= e($notification['subject']) ?></td>
                <td>
                    <?php if ($notification['source'] === 'system_email'): ?>
                        <span class="cv-badge cv-badge--neutral">System email</span>
                    <?php else: ?>
                        <?= e($notification['created_by_name'] ?? 'Admin') ?>
                    <?php endif; ?>
                </td>
                <td><?= (int) $notification['recipient_count'] ?></td>
                <td><?= (int) $notification['read_count'] ?> / <?= (int) $notification['recipient_count'] ?></td>
                <td style="white-space:nowrap;"><?= e((string) $notification['created_at']) ?></td>
                <td><a href="/admin/client-notifications/<?= (int) $notification['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($sent === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No notifications sent yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
