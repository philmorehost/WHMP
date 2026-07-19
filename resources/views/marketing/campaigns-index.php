<?php
/** @var array<int, array<string, mixed>> $campaigns */
/** @var array<int, array<string, mixed>> $groups */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Mail Campaigns</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#campaigns-table', 'placeholder' => 'Search campaigns...']) ?>
    </div>
    <table class="cv-table" id="campaigns-table">
        <thead><tr><th>Subject</th><th>Audience</th><th>Status</th><th>Sent / Opened</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $campaign): ?>
            <tr>
                <td><a href="/admin/campaigns/<?= (int) $campaign['id'] ?>"><?= e($campaign['subject']) ?></a></td>
                <td><?= e((string) ($campaign['group_name'] ?? 'All active clients')) ?></td>
                <td>
                    <?php if ($campaign['status'] === 'sent'): ?>
                        <span class="cv-badge cv-badge--success">Sent</span>
                    <?php elseif ($campaign['status'] === 'sending'): ?>
                        <span class="cv-badge cv-badge--neutral">Sending</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Draft</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $campaign['recipient_count'] ?> / <?= (int) $campaign['opened_count'] ?></td>
                <td>
                    <?php if ($campaign['status'] === 'draft'): ?>
                        <form method="post" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/send" data-confirm="Send this campaign now?"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Send Now</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($campaigns === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No campaigns yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h3>New Campaign</h3>
    <form method="post" action="/admin/campaigns"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Audience</label>
            <select class="cv-select" name="client_group_id">
                <option value="">All active clients</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body (HTML)</label>
            <textarea class="cv-input" name="body" rows="8" required></textarea>
        </div>
        <button class="cv-btn" type="submit">Save as Draft</button>
    </form>
</div>
