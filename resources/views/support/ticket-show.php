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
/** @var array<string, mixed>|null $mergeConfirmTargetTicket */
/** @var array<string, mixed>|null $mergedFromTicket */
/** @var int|null $mergeTargetPrefill a ticket id to preselect in the merge picker (from the index page's "Merge Selected") */
/** @var string|null $splitError */
/** @var int|null $splitFromId set on the new ticket right after a split lands here */
$id = (int) $ticket['id'];

// "Full Name (email)" when the ticket has a client account, otherwise just
// the raw reporter email — used anywhere an admin needs to positively
// identify whose ticket they're about to merge into/from.
$identityLabel = static function (array $t): string {
    $name = trim((string) ($t['client_first_name'] ?? '') . ' ' . (string) ($t['client_last_name'] ?? ''));

    return $name !== '' ? "{$name} ({$t['email']})" : ((string) $t['email'] . ' — no client account');
};
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
        ⚠️ Ticket #<?= $mergeConfirmTargetId ?> belongs to a <strong>different client</strong> than this ticket. Double-check before continuing:
        <ul style="margin:8px 0;">
            <li>This ticket (#<?= $id ?>): <strong><?= e($identityLabel($ticket)) ?></strong></li>
            <li>Target ticket (#<?= $mergeConfirmTargetId ?>): <strong><?= $mergeConfirmTargetTicket !== null ? e($identityLabel($mergeConfirmTargetTicket)) : 'not found' ?></strong>
                <?php if ($mergeConfirmTargetTicket !== null): ?> — "<?= e((string) $mergeConfirmTargetTicket['subject']) ?>"<?php endif; ?>
            </li>
        </ul>
        <form method="post" action="/admin/tickets/<?= $id ?>/merge">
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
        Ticket #<?= $mergedFromId ?><?= $mergedFromTicket !== null ? ' (' . e($identityLabel($mergedFromTicket)) . ')' : '' ?> was merged into this ticket. Its replies and attachments now appear below.
        <?php if ($mergeCrossClientNotice): ?>
            <br>⚠️ <strong>Note:</strong> ticket #<?= $mergedFromId ?> belonged to a different client than this one — verify this merge was intentional.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($splitError)): ?>
    <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-3);">
        ⚠️ Could not split the ticket: <?= e($splitError) ?>
    </div>
<?php endif; ?>

<?php if ($splitFromId !== null): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        ✂️ This conversation was split out of ticket #<?= $splitFromId ?>. The original keeps the earlier messages.
    </div>
<?php endif; ?>

<?php if (($blockedSenderAdded ?? false) === true): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        🚫 Sender <strong><?= e($ticket['email']) ?></strong> is now blocked — mail piping will ignore messages from it.
    </div>
<?php endif; ?>

<?php if (($blockedSenderError ?? false) === true): ?>
    <div class="cv-alert cv-alert--danger" style="margin-bottom:var(--cv-space-3);">
        This ticket has no sender email to block.
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
                <span class="admin-ticket-hero__meta-value">
                    <?php $clientName = trim((string) ($ticket['client_first_name'] ?? '') . ' ' . (string) ($ticket['client_last_name'] ?? '')); ?>
                    <?php if ($clientName !== ''): ?>
                        <?= e($clientName) ?> (<?= e($ticket['email']) ?>)
                    <?php else: ?>
                        <?= e($ticket['email']) ?> <span style="color:rgba(255,255,255,.5);">(no client account)</span>
                    <?php endif; ?>
                </span>
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

            <?php $senderBlockedPattern = $senderBlockedPattern ?? null; ?>
            <?php if ($senderBlockedPattern !== null): ?>
                <span class="admin-ticket-btn admin-ticket-btn--secondary" style="cursor:default; background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.4); color:#fca5a5;"
                      title="Sender <?= e($ticket['email']) ?> is blocked by the pattern <?= e($senderBlockedPattern) ?>">🚫 Sender blocked</span>
            <?php elseif (trim((string) $ticket['email']) !== ''): ?>
                <form method="post" action="/admin/tickets/<?= $id ?>/block-sender" data-confirm="Block <?= e($ticket['email']) ?>? Mail piping will ignore all future messages from this sender and they will no longer become tickets or replies."><?= csrf_field() ?>
                    <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">🚫 Block Sender</button>
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

        <form method="post" action="/admin/tickets/<?= $id ?>/merge" class="admin-ticket-action-group" style="align-items:flex-end;margin-top:16px;"
              data-confirm="Merge ticket #<?= $id ?> into the selected ticket? Its replies and attachments move over, and it's closed — this can't be undone."><?= csrf_field() ?>
            <?php
            // The "Merge Selected" shortcut on the tickets list can prefill
            // a target that isn't one of this client's own tickets (that's
            // exactly the cross-client case the confirm step exists for) —
            // when that happens, fall back to the plain number field instead
            // of a dropdown that would have nowhere to put the prefilled id.
            $prefillInSameClientList = $mergeTargetPrefill !== null
                && in_array($mergeTargetPrefill, array_map(static fn (array $t): int => (int) $t['id'], $sameClientTickets), true);
            $useDropdown = $sameClientTickets !== [] && ($mergeTargetPrefill === null || $prefillInSameClientList);
            ?>
            <div style="min-width:220px;">
                <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Merge Into Ticket #</label>
                <?php if ($useDropdown): ?>
                    <select name="target_ticket_id" style="width:100%;">
                        <option value="">— Choose a ticket —</option>
                        <?php foreach ($sameClientTickets as $other): ?>
                            <option value="<?= (int) $other['id'] ?>" <?= $mergeTargetPrefill === (int) $other['id'] ? 'selected' : '' ?>>#<?= (int) $other['id'] ?> — <?= e($other['subject']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="number" min="1" name="target_ticket_id" placeholder="e.g. 42" value="<?= $mergeTargetPrefill !== null ? (int) $mergeTargetPrefill : '' ?>" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
                    <?php if ($mergeTargetPrefill !== null): ?>
                        <p style="font-size:.75rem;color:rgba(255,255,255,.6);margin:4px 0 0;">Prefilled from your selection on the ticket list — not one of this client's own tickets, so double-check before merging.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">🔀 Merge Ticket</button>
        </form>
        <?php if ($sameClientTickets === [] && $ticket['client_id'] !== null): ?>
            <p style="font-size:.8rem;color:rgba(255,255,255,.6);margin-top:4px;">This client has no other open tickets — enter any ticket # to merge into a different client's ticket (you'll be asked to confirm).</p>
        <?php elseif ($ticket['client_id'] === null): ?>
            <p style="font-size:.8rem;color:rgba(255,255,255,.6);margin-top:4px;">This ticket has no client on file — merging into any ticket # will need cross-client confirmation.</p>
        <?php endif; ?>

        <form method="post" action="/admin/tickets/<?= $id ?>/split" class="admin-ticket-action-group" style="align-items:flex-end;margin-top:16px;flex-wrap:wrap;"
              data-confirm="Split this ticket at the chosen reply? That reply and everything after it move into a new ticket with its own subject — the earlier conversation stays here."><?= csrf_field() ?>
            <div style="min-width:180px;">
                <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Split From Reply #</label>
                <select name="from_reply_id" style="width:100%;">
                    <option value="">— Choose a reply —</option>
                    <?php foreach ($replies as $reply): ?>
                        <?php if ($reply['id'] === (int) ($replies[0]['id'] ?? 0)) { continue; } // can't split from the opening message ?>
                        <option value="<?= (int) $reply['id'] ?>" <?= (int) ($splitFromId ?? 0) === (int) $reply['id'] ? 'selected' : '' ?>>
                            #<?= (int) $reply['id'] ?> — <?= e($reply['author_name']) ?> · <?= e($reply['created_at']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:220px;flex:1;">
                <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">New Ticket Subject</label>
                <input type="text" name="subject" placeholder="Subject for the split-off conversation" value="<?= e((string) ($ticket['subject'] ?? '')) ?> — continued" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:rgba(255,255,255,.1); color:white;">
            </div>
            <div style="min-width:160px;">
                <label style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px;">Department</label>
                <select name="department_id" style="width:100%;">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $ticket['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="admin-ticket-btn admin-ticket-btn--secondary" type="submit">✂️ Split Ticket</button>
        </form>
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
