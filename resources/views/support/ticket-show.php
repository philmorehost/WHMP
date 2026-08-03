<?php
/** @var array<string, mixed> $ticket */
/** @var array<int, array<string, mixed>> $replies */
/** @var array<int, array<string, mixed>> $departments */
/** @var array<int, array<string, mixed>> $cannedReplies */
/** @var array<int, array<string, mixed>> $admins */
/** @var string|null $aiSuggestion */
/** @var string|null $aiError */
/** @var array<int, array<int, array<string, mixed>>> $attachments grouped by reply_id (0 = opening message) */
/** @var array<int, array<string, mixed>> $sameClientTickets other open tickets from this ticket's client, for the merge picker */
/** @var string|null $mergeError */
/** @var int|null $mergeConfirmTargetId set when a merge needs cross-client confirmation */
/** @var int|null $mergedFromId set on the surviving ticket right after a merge lands here */
/** @var bool $mergeCrossClientNotice */
$id = (int) $ticket['id'];
?>
<style>
/* Admin Ticket Detail Styles */
.admin-ticket-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-ticket-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-ticket-hero__content {
    position: relative;
    z-index: 1;
}
.admin-ticket-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.admin-ticket-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-ticket-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
    word-break: break-word;
}
.admin-ticket-hero__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 24px;
    margin-top: 24px;
}
.admin-ticket-hero__meta-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-ticket-hero__meta-label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.admin-ticket-hero__meta-value {
    font-size: .95rem;
    color: white;
    font-weight: 600;
}

/* Actions Toolbar */
.admin-ticket-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.admin-ticket-action-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-ticket-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-ticket-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-ticket-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-ticket-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-ticket-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}

/* Select/Input in Action */
.admin-ticket-action-group select {
    padding: 8px 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 6px;
    background: rgba(255,255,255,.1);
    color: white;
    font-size: .85rem;
    font-weight: 600;
}
.admin-ticket-action-group select:focus {
    outline: none;
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-ticket-action-group select option {
    background: #1e293b;
    color: white;
}

/* Ticket Card */
.admin-ticket-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-ticket-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-ticket-card__body {
    padding: 24px;
}

/* Reply Messages */
.admin-ticket-reply {
    border-bottom: 1px solid var(--cv-border-default);
    padding: 20px 0;
}
.admin-ticket-reply:last-child {
    border-bottom: none;
}
.admin-ticket-reply__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.admin-ticket-reply__author {
    font-weight: 700;
    color: var(--cv-text-primary);
}
.admin-ticket-reply__badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(37,99,235,.15));
    color: #3b82f6;
    border: 1px solid rgba(59,130,246,.3);
}
.admin-ticket-reply__badge--private {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-ticket-reply__time {
    font-size: .85rem;
    color: var(--cv-text-secondary);
}
.admin-ticket-reply__message {
    white-space: pre-wrap;
    word-break: break-word;
    color: var(--cv-text-primary);
    line-height: 1.6;
    margin: 12px 0;
}
.admin-ticket-reply--private {
    background: linear-gradient(135deg, rgba(255,223,85,.1), rgba(255,213,0,.05));
}

/* Form Field */
.admin-ticket-field {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-ticket-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-ticket-field input,
.admin-ticket-field select,
.admin-ticket-field textarea {
    padding: 10px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
}
.admin-ticket-field textarea {
    resize: vertical;
    min-height: 120px;
}
.admin-ticket-field input:focus,
.admin-ticket-field select:focus,
.admin-ticket-field textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

/* Checkbox */
.admin-ticket-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 16px 0;
}
.admin-ticket-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .admin-ticket-hero {
        padding: 32px 24px;
    }
    .admin-ticket-hero__title {
        font-size: 1.5rem;
    }
    .admin-ticket-hero__meta {
        grid-template-columns: 1fr;
    }
    .admin-ticket-actions {
        flex-direction: column;
    }
    .admin-ticket-actions form,
    .admin-ticket-action-group {
        width: 100%;
    }
}
</style>

<?php if ($mergeError !== null && $mergeError !== ''): ?>
    <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-3);"><?= e($mergeError) ?></div>
<?php endif; ?>

<?php if ($mergeConfirmTargetId !== null): ?>
    <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-3);">
        ⚠️ Ticket #<?= $mergeConfirmTargetId ?> belongs to a <strong>different client</strong> than this ticket.
        Merging across clients is unusual — double-check the ticket number before continuing.
        <form method="post" action="/admin/tickets/<?= $id ?>/merge" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="target_ticket_id" value="<?= $mergeConfirmTargetId ?>">
            <input type="hidden" name="confirm_cross_client" value="1">
            <button type="submit" class="cv-btn cv-btn--danger">Yes, merge into #<?= $mergeConfirmTargetId ?> anyway</button>
            <a href="/admin/tickets/<?= $id ?>" class="cv-btn cv-btn--secondary">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<?php if ($mergedFromId !== null): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        Ticket #<?= $mergedFromId ?> was merged into this ticket. Its replies and attachments now appear below.
        <?php if ($mergeCrossClientNotice): ?>
            <br>⚠️ <strong>Note:</strong> ticket #<?= $mergedFromId ?> belonged to a different client — verify this merge was intentional.
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Hero Section -->
<div class="admin-ticket-hero">
    <div class="admin-ticket-hero__content">
        <a href="/admin/tickets" class="admin-ticket-hero__back">
            <span>←</span>
            <span>Back to Tickets</span>
        </a>
        <h1 class="admin-ticket-hero__title">#<?= $id ?> — <?= e($ticket['subject']) ?></h1>

        <div class="admin-ticket-hero__meta">
            <div class="admin-ticket-hero__meta-item">
                <span class="admin-ticket-hero__meta-label">📧 From</span>
                <span class="admin-ticket-hero__meta-value"><?= e($ticket['email']) ?></span>
            </div>
            <div class="admin-ticket-hero__meta-item">
                <span class="admin-ticket-hero__meta-label">📁 Department</span>
                <span class="admin-ticket-hero__meta-value"><?= e($ticket['department_name']) ?></span>
            </div>
            <div class="admin-ticket-hero__meta-item">
                <span class="admin-ticket-hero__meta-label">🎯 Status</span>
                <span class="admin-ticket-hero__meta-value"><?= e($ticket['status']) ?></span>
            </div>
        </div>

        <!-- Actions Toolbar -->
        <div class="admin-ticket-actions">
            <?php if ($ticket['status'] === 'closed'): ?>
                <form method="post" action="/admin/tickets/<?= $id ?>/reopen"><?= csrf_field() ?>
                    <button class="admin-ticket-btn admin-ticket-btn--primary" type="submit">🔓 Reopen</button>
                </form>
            <?php else: ?>
                <form method="post" action="/admin/tickets/<?= $id ?>/close"><?= csrf_field() ?>
                    <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">✕ Close</button>
                </form>
            <?php endif; ?>

            <form method="post" action="/admin/tickets/<?= $id ?>/assign" class="admin-ticket-action-group"><?= csrf_field() ?>
                <select name="admin_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($admins as $admin): ?>
                        <option value="<?= (int) $admin['id'] ?>" <?= (int) ($ticket['assigned_admin_id'] ?? 0) === (int) $admin['id'] ? 'selected' : '' ?>><?= e($admin['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">👤 Assign</button>
            </form>

            <form method="post" action="/admin/tickets/<?= $id ?>/priority" class="admin-ticket-action-group"><?= csrf_field() ?>
                <select name="priority">
                    <option value="low" <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                    <option value="medium" <?= $ticket['priority'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="high" <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                </select>
                <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">📊 Priority</button>
            </form>

            <form method="post" action="/admin/tickets/<?= $id ?>/department" class="admin-ticket-action-group"><?= csrf_field() ?>
                <select name="department_id">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $ticket['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">📤 Move</button>
            </form>
        </div>

        <?php if ($ticket['client_id'] !== null): ?>
            <form method="post" action="/admin/tickets/<?= $id ?>/billable" style="display:flex;gap:12px;align-items:flex-end;margin-top:24px;flex-wrap:wrap;"><?= csrf_field() ?>
                <div style="flex:1; min-width:250px;">
                    <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Bill Client — Description</label>
                    <input type="text" name="description" value="Support: <?= e($ticket['subject']) ?>" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
                </div>
                <div style="min-width:120px;">
                    <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
                </div>
                <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">💳 Make Billable</button>
            </form>
        <?php endif; ?>

        <form method="post" action="/admin/tickets/<?= $id ?>/merge" style="display:flex;gap:12px;align-items:flex-end;margin-top:16px;flex-wrap:wrap;"
              data-confirm="Merge ticket #<?= $id ?> into the selected ticket? Its replies and attachments move over, and it's closed — this can't be undone."><?= csrf_field() ?>
            <div style="min-width:220px;">
                <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Merge Into Ticket #</label>
                <?php if ($sameClientTickets !== []): ?>
                    <select name="target_ticket_id" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
                        <option value="">— Choose a ticket —</option>
                        <?php foreach ($sameClientTickets as $other): ?>
                            <option value="<?= (int) $other['id'] ?>">#<?= (int) $other['id'] ?> — <?= e($other['subject']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="number" min="1" name="target_ticket_id" placeholder="e.g. 42" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
                <?php endif; ?>
            </div>
            <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">🔀 Merge Ticket</button>
        </form>
        <?php if ($sameClientTickets === [] && $ticket['client_id'] !== null): ?>
            <p style="font-size:.8rem;color:rgba(255,255,255,.6);margin-top:4px;">This client has no other open tickets — enter any ticket # to merge into a different client's ticket (you'll be asked to confirm).</p>
        <?php elseif ($ticket['client_id'] === null): ?>
            <p style="font-size:.8rem;color:rgba(255,255,255,.6);margin-top:4px;">This ticket has no client on file — merging into any ticket # will need cross-client confirmation.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Reply Thread -->
<div class="admin-ticket-card">
    <h2 class="admin-ticket-card__title">💬 Conversation</h2>
    <div class="admin-ticket-card__body" style="padding:0;">
        <?php foreach ($replies as $i => $reply):
            $replyAttachments = $attachments[(int) $reply['id']] ?? [];
            if ($i === 0) { $replyAttachments = array_merge($attachments[0] ?? [], $replyAttachments); }
        ?>
            <div class="admin-ticket-reply <?= $reply['is_private'] ? 'admin-ticket-reply--private' : '' ?>">
                <div class="admin-ticket-reply__header">
                    <span class="admin-ticket-reply__author"><?= e($reply['author_name']) ?></span>
                    <span class="admin-ticket-reply__badge"><?= e($reply['author_type']) ?></span>
                    <?php if ($reply['is_private']): ?>
                        <span class="admin-ticket-reply__badge admin-ticket-reply__badge--private">🔒 Private Note</span>
                    <?php endif; ?>
                    <span class="admin-ticket-reply__time"><?= e($reply['created_at']) ?></span>
                </div>
                <div class="admin-ticket-reply__message"><?= e($reply['message']) ?></div>
                <?= $view->partial('partials.ticket-attachments', ['items' => $replyAttachments, 'baseUrl' => "/admin/tickets/{$id}/attachments"]) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- AI Support Copilot -->
<div class="admin-ticket-card">
    <h2 class="admin-ticket-card__title">🤖 AI Support Copilot</h2>
    <div class="admin-ticket-card__body">
        <form method="post" action="/admin/tickets/<?= $id ?>/ai-suggest" style="margin:0;"><?= csrf_field() ?>
            <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">✨ Suggest Reply</button>
        </form>
        <?php if ($aiError !== null): ?>
            <div style="margin-top:16px; padding:12px 16px; background:linear-gradient(135deg, rgba(239,68,68,.15), rgba(220,38,38,.1)); border:1px solid rgba(239,68,68,.3); border-radius:8px; color:#dc2626; font-size:.9rem;">
                ⚠️ <?= e($aiError) ?>
            </div>
        <?php elseif ($aiSuggestion !== null): ?>
            <div class="admin-ticket-field" style="margin-top:16px;">
                <label>Suggested Reply</label>
                <textarea id="cv-ai-suggestion" readonly><?= e($aiSuggestion) ?></textarea>
            </div>
            <button class="admin-ticket-btn admin-ticket-btn--secondary" type="button" data-copy-value-from="cv-ai-suggestion" data-copy-value-to="cv-reply-message" style="margin-top:12px;">→ Insert into Reply</button>
        <?php endif; ?>
    </div>
</div>

<!-- Reply Form -->
<div class="admin-ticket-card">
    <h2 class="admin-ticket-card__title">✉️ Send Reply</h2>
    <div class="admin-ticket-card__body">
        <?php if ($cannedReplies !== []): ?>
            <div class="admin-ticket-field">
                <label>Insert Canned Reply</label>
                <select data-insert-value-into="cv-reply-message">
                    <option value="">-- Select a canned reply --</option>
                    <?php foreach ($cannedReplies as $canned): ?>
                        <option value="<?= e($canned['body']) ?>"><?= e($canned['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <form method="post" action="/admin/tickets/<?= $id ?>/reply" enctype="multipart/form-data"><?= csrf_field() ?>
            <div class="admin-ticket-field">
                <label>Message</label>
                <textarea name="message" id="cv-reply-message" placeholder="Type your reply… emojis and all characters welcome ✨"></textarea>
            </div>
            <div class="admin-ticket-field">
                <label>Attachments <span style="color:var(--cv-text-secondary);font-weight:400;">(images & documents, up to 10 MB each)</span></label>
                <input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.zip">
            </div>
            <label class="admin-ticket-checkbox">
                <input type="checkbox" name="is_private" value="1">
                <span>🔒 Private note (not visible to client)</span>
            </label>
            <button class="admin-ticket-btn admin-ticket-btn--primary" type="submit">📤 Send Reply</button>
        </form>
    </div>
</div>

<?php /* The "Insert into Reply" button and the canned-reply <select> are
       wired via delegated listeners in app.js (data-copy-value-from /
       data-insert-value-into) — inline <script> here is blocked by the CSP. */ ?>
