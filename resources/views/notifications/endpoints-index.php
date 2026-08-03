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

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">What this page is for</h2>
    <p style="color:var(--cv-text-secondary);">
        This is <strong>staff-facing</strong> alerting, not client messaging — it does not email or notify your
        clients about anything. An endpoint here pushes a short message to your own team (a Slack channel, or any
        webhook URL you control) the moment specific system events happen, so your staff sees things like a new
        order or a fraud-flagged order without having to sit refreshing the admin dashboard.
    </p>
    <p style="color:var(--cv-text-secondary);">
        Only four events are wired up right now: <strong>order placed</strong>, <strong>invoice paid</strong>,
        <strong>ticket opened</strong>, and <strong>order fraud-flagged</strong>. Each fires a one-line message —
        for example a new order posts <em>"New order #482 placed — $49.00 (client@example.com)"</em>.
    </p>

    <h3 style="margin-top:var(--cv-space-4);">Step-by-step: Slack</h3>
    <ol style="color:var(--cv-text-secondary);line-height:1.8;">
        <li>In Slack, go to <strong>api.slack.com/apps</strong> &rsaquo; <strong>Create New App</strong> &rsaquo; <strong>From scratch</strong> (or reuse an existing app), then open <strong>Incoming Webhooks</strong> and switch it on.</li>
        <li>Click <strong>Add New Webhook to Workspace</strong>, pick the channel that should receive alerts, and authorize it.</li>
        <li>Copy the Webhook URL Slack gives you — it looks like <code>https://hooks.slack.com/services/T000/B000/XXXXXXXX</code>.</li>
        <li>Below, set <strong>Type</strong> to <em>Slack</em>, give it a <strong>Name</strong> you'll recognize later (e.g. "#billing-alerts"), and paste the URL. Leave <strong>Secret</strong> blank — Slack doesn't use it.</li>
        <li>Tick the event(s) that channel should hear about, then <strong>Add Endpoint</strong>. It's live immediately — no restart needed.</li>
    </ol>

    <h3 style="margin-top:var(--cv-space-4);">Step-by-step: Generic Webhook</h3>
    <ol style="color:var(--cv-text-secondary);line-height:1.8;">
        <li>Stand up (or point at) an HTTPS endpoint on your own server that accepts a JSON <code>POST</code>.</li>
        <li>Set <strong>Type</strong> to <em>Generic Webhook</em>, give it a name, and enter that URL.</li>
        <li>
            Optional but recommended: set a <strong>Secret</strong>. Every request will then include an
            <code>X-CodeVault-Signature</code> header — an HMAC-SHA256 of the request body using your secret — so
            your endpoint can verify the request genuinely came from here (the same pattern Stripe and GitHub
            webhooks use) rather than acting on it blindly.
        </li>
        <li>Tick the event(s) to subscribe to and <strong>Add Endpoint</strong>.</li>
        <li>
            Each request body looks like:
            <pre style="background:var(--cv-bg-surface-sunken);padding:var(--cv-space-2);border-radius:6px;overflow-x:auto;font-size:var(--cv-text-xs);">{
  "message": "New order #482 placed — $49.00 (client@example.com)",
  "context": { "orderId": 482, "total": "49.00" },
  "timestamp": "2026-08-01T14:32:00+00:00"
}</pre>
        </li>
    </ol>

    <h3 style="margin-top:var(--cv-space-4);">Managing endpoints</h3>
    <p style="color:var(--cv-text-secondary);">
        Use <strong>Deactivate</strong> to pause an endpoint temporarily (e.g. while a channel or receiving server is
        down) without losing its configuration — reactivate it later with one click. <strong>Delete</strong> removes
        it permanently. A Slack outage or unreachable webhook never blocks the order/invoice/ticket action that
        triggered it — sends are best-effort and fail silently from the client/admin's point of view.
    </p>
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
