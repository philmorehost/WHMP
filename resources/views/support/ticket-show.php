<?php
/** @var array<string, mixed> $ticket */
/** @var array<int, array<string, mixed>> $replies */
/** @var array<int, array<string, mixed>> $departments */
/** @var array<int, array<string, mixed>> $cannedReplies */
/** @var array<int, array<string, mixed>> $admins */
/** @var string|null $aiSuggestion */
/** @var string|null $aiError */
$id = (int) $ticket['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">#<?= $id ?> &mdash; <?= e($ticket['subject']) ?></h1>
    <p><a href="/admin/tickets">&larr; Back to tickets</a></p>
    <p><strong>From:</strong> <?= e($ticket['email']) ?> &middot; <strong>Department:</strong> <?= e($ticket['department_name']) ?> &middot; <strong>Status:</strong> <?= e($ticket['status']) ?></p>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);flex-wrap:wrap;">
        <?php if ($ticket['status'] === 'closed'): ?>
            <form method="post" action="/admin/tickets/<?= $id ?>/reopen"><?= csrf_field() ?><button class="cv-btn" type="submit">Reopen</button></form>
        <?php else: ?>
            <form method="post" action="/admin/tickets/<?= $id ?>/close"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit">Close</button></form>
        <?php endif; ?>

        <form method="post" action="/admin/tickets/<?= $id ?>/assign" style="display:flex;gap:var(--cv-space-1);"><?= csrf_field() ?>
            <select class="cv-input" name="admin_id">
                <option value="">Unassigned</option>
                <?php foreach ($admins as $admin): ?>
                    <option value="<?= (int) $admin['id'] ?>" <?= (int) ($ticket['assigned_admin_id'] ?? 0) === (int) $admin['id'] ? 'selected' : '' ?>><?= e($admin['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="cv-btn cv-btn--secondary" type="submit">Assign</button>
        </form>

        <form method="post" action="/admin/tickets/<?= $id ?>/priority" style="display:flex;gap:var(--cv-space-1);"><?= csrf_field() ?>
            <select class="cv-input" name="priority">
                <option value="low" <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                <option value="medium" <?= $ticket['priority'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                <option value="high" <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>High</option>
            </select>
            <button class="cv-btn cv-btn--secondary" type="submit">Set Priority</button>
        </form>

        <form method="post" action="/admin/tickets/<?= $id ?>/department" style="display:flex;gap:var(--cv-space-1);"><?= csrf_field() ?>
            <select class="cv-input" name="department_id">
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= (int) $ticket['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="cv-btn cv-btn--secondary" type="submit">Move</button>
        </form>
    </div>

    <?php if ($ticket['client_id'] !== null): ?>
        <form method="post" action="/admin/tickets/<?= $id ?>/billable" style="display:flex;gap:var(--cv-space-1);align-items:end;margin-top:var(--cv-space-3);flex-wrap:wrap;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Bill Client — Description</label>
                <input class="cv-input" name="description" value="Support: <?= e($ticket['subject']) ?>">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Amount</label>
                <input class="cv-input" type="number" step="0.01" min="0.01" name="amount" style="width:8rem;">
            </div>
            <button class="cv-btn cv-btn--secondary" type="submit">Convert to Billable Item</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <?php foreach ($replies as $reply): ?>
        <div style="border-bottom:1px solid var(--cv-border);padding:var(--cv-space-3) 0;<?= $reply['is_private'] ? 'background:var(--cv-surface-warning, #fff8e1);' : '' ?>">
            <p>
                <strong><?= e($reply['author_name']) ?></strong>
                <span class="cv-badge cv-badge--neutral"><?= e($reply['author_type']) ?></span>
                <?php if ($reply['is_private']): ?><span class="cv-badge cv-badge--danger">Private Note</span><?php endif; ?>
                <span style="color:var(--cv-text-secondary);"> &middot; <?= e($reply['created_at']) ?></span>
            </p>
            <p style="white-space:pre-wrap;"><?= e($reply['message']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">AI Support Copilot</h2>
    <form method="post" action="/admin/tickets/<?= $id ?>/ai-suggest"><?= csrf_field() ?>
        <button class="cv-btn cv-btn--secondary" type="submit">Suggest Reply</button>
    </form>
    <?php if ($aiError !== null): ?>
        <div class="cv-field-error" style="margin-top:var(--cv-space-3);"><?= e($aiError) ?></div>
    <?php elseif ($aiSuggestion !== null): ?>
        <div class="cv-field" style="margin-top:var(--cv-space-3);">
            <label class="cv-label">Suggested Reply</label>
            <textarea class="cv-input" id="cv-ai-suggestion" rows="6" readonly><?= e($aiSuggestion) ?></textarea>
        </div>
        <button class="cv-btn cv-btn--secondary" type="button" id="cv-ai-use-suggestion">Insert into Reply</button>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Reply</h2>
    <?php if ($cannedReplies !== []): ?>
        <div class="cv-field">
            <label class="cv-label">Insert Canned Reply</label>
            <select class="cv-input" id="cv-canned-reply-select">
                <option value="">-- Select --</option>
                <?php foreach ($cannedReplies as $canned): ?>
                    <option value="<?= e($canned['body']) ?>"><?= e($canned['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <form method="post" action="/admin/tickets/<?= $id ?>/reply"><?= csrf_field() ?>
        <div class="cv-field">
            <textarea class="cv-input" name="message" id="cv-reply-message" rows="6" required></textarea>
        </div>
        <label style="display:flex;align-items:center;gap:var(--cv-space-1);margin-bottom:var(--cv-space-2);">
            <input type="checkbox" name="is_private" value="1"> Private note (not visible to client)
        </label>
        <button class="cv-btn" type="submit">Send Reply</button>
    </form>
</div>

<?php if ($aiSuggestion !== null): ?>
<script>
document.getElementById('cv-ai-use-suggestion').addEventListener('click', function () {
    document.getElementById('cv-reply-message').value = document.getElementById('cv-ai-suggestion').value;
});
</script>
<?php endif; ?>

<?php if ($cannedReplies !== []): ?>
<script>
document.getElementById('cv-canned-reply-select').addEventListener('change', function () {
    if (this.value) {
        document.getElementById('cv-reply-message').value = this.value;
    }
});
</script>
<?php endif; ?>
