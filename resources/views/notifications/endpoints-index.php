<?php
/** @var array<int, array<string, mixed>> $endpoints */
/** @var array<int, string> $hookPoints */
/** @var array<string, array{name: string, description: string, version: string, author: string}> $notificationModules */
/** @var string|null $error */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Notification Endpoints</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <table class="cv-table">
        <thead><tr><th>Name</th><th>Type</th><th>URL</th><th>Events</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($endpoints as $endpoint): ?>
            <tr>
                <td><?= e($endpoint['name']) ?></td>
                <td><?= e(ucfirst($endpoint['type'])) ?></td>
                <td style="max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($endpoint['url']) ?></td>
                <td><?= e(implode(', ', json_decode((string) $endpoint['events'], true) ?: [])) ?></td>
                <td>
                    <?php if ((int) $endpoint['is_active'] === 1): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:var(--cv-space-2);">
                    <form method="post" action="/admin/notification-endpoints/<?= (int) $endpoint['id'] ?>/toggle-active"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--secondary" type="submit"><?= (int) $endpoint['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" action="/admin/notification-endpoints/<?= (int) $endpoint['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($endpoints === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No notification endpoints configured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cv-card">
    <h3>Add Endpoint</h3>
    <form method="post" action="/admin/notification-endpoints"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Type</label>
            <select class="cv-select" name="type" required>
                <option value="slack">Slack (incoming webhook)</option>
                <option value="webhook">Generic Webhook</option>
                <?php foreach ($notificationModules as $slug => $metadata): ?>
                    <option value="<?= e($slug) ?>"><?= e($metadata['name']) ?> (module)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Name</label>
            <input class="cv-input" name="name" placeholder="#billing-alerts" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">URL</label>
            <input class="cv-input" type="url" name="url" placeholder="https://hooks.slack.com/services/..." required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Secret (webhook only — signs the payload)</label>
            <input class="cv-input" name="secret">
        </div>
        <div class="cv-field">
            <label class="cv-label">Events</label>
            <?php foreach ($hookPoints as $hookPoint): ?>
                <div>
                    <label>
                        <input type="checkbox" name="events[]" value="<?= e($hookPoint) ?>">
                        <?= e($hookPoint) ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="cv-btn" type="submit">Add Endpoint</button>
    </form>
</div>
