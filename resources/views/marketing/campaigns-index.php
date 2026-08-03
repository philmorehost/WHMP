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
                <td>
                    <?php if (!empty($campaign['client_first_name'])): ?>
                        👤 <?= e($campaign['client_first_name'] . ' ' . $campaign['client_last_name'] . ' (' . $campaign['client_email'] . ')') ?>
                    <?php elseif (!empty($campaign['group_name'])): ?>
                        📁 Group: <?= e($campaign['group_name']) ?>
                    <?php else: ?>
                        🌐 All active clients
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($campaign['status'] === 'sent'): ?>
                        <span class="cv-badge cv-badge--success">Sent</span>
                    <?php elseif ($campaign['status'] === 'sending'): ?>
                        <span class="cv-badge cv-badge--neutral">Sending</span>
                    <?php elseif ($campaign['status'] === 'paused'): ?>
                        <span class="cv-badge cv-badge--warning" style="background:rgba(245,158,11,.15);color:#b45309;">⏸️ Paused</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--neutral">Draft</span>
                    <?php endif; ?>
                </td>
                <td title="Opened is a minimum — many mail clients block the tracking image by default"><?= (int) $campaign['recipient_count'] ?> / <?= (int) $campaign['opened_count'] ?></td>
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
            <label class="cv-label">Audience Target</label>
            <select class="cv-select" name="target_type" id="campaign-target-type">
                <option value="all">🌐 All active clients</option>
                <option value="group">📁 Client Group</option>
                <option value="individual">👤 Individual Client</option>
                <option value="external">✉️ External email addresses</option>
            </select>
        </div>
        <div class="cv-field" id="target-external-field" style="display:none;">
            <label class="cv-label">External Email Addresses</label>
            <textarea class="cv-input" name="external_emails" rows="5"
                      placeholder="press@example.com, partner@example.com&#10;prospect@example.com"
                      style="font-family:monospace;font-size:.85rem;"></textarea>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                Separate with commas, semicolons or one per line. These recipients don't need a client account.
                Invalid addresses are ignored, duplicates are removed, and anyone who is already a client
                receives only one copy.
            </div>
        </div>
        <div class="cv-field" id="target-group-field" style="display:none;">
            <label class="cv-label">Target Client Group</label>
            <select class="cv-select" name="client_group_id">
                <option value="">— Select Group —</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field" id="target-individual-field" style="display:none;">
            <label class="cv-label">Target Individual Client</label>
            <select class="cv-select" name="client_id">
                <option value="">— Select Client —</option>
                <?php if (isset($clients)): ?>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>"><?= e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <?php
        // AI copilot. Fills the subject/body fields below — it never creates or
        // sends anything, so a poor suggestion costs one click to discard.
        // Behaviour is in app.js; inline scripts are blocked by the CSP.
        ?>
        <div class="cv-field" data-campaign-copilot
             style="border:1px solid var(--cv-border-default);border-radius:10px;padding:var(--cv-space-3);background:var(--cv-bg-surface-sunken);">
            <label class="cv-label" style="display:flex;align-items:center;gap:6px;">✨ AI Copilot</label>
            <textarea class="cv-input" data-copilot-brief rows="2"
                      placeholder="What should this campaign say? e.g. announce our new client portal, invite feedback"></textarea>
            <div style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;margin-top:var(--cv-space-2);">
                <button type="button" class="cv-btn cv-btn--secondary" data-copilot-action="write">✨ Help me write</button>
                <button type="button" class="cv-btn cv-btn--secondary" data-copilot-action="refine">🪄 Refine what I wrote</button>
                <span data-copilot-status style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"></span>
            </div>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                Writes into the Subject and Body fields below — review and edit before saving. Nothing is sent.
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" data-campaign-subject required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body (HTML)</label>
            <textarea class="cv-input" name="body" data-campaign-body rows="8" required></textarea>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                Write plain text or HTML — either works. Use <code>[Client Name]</code> or <code>{{client_name}}</code>
                anywhere you want the recipient's real name; each recipient gets their own name substituted in when
                the campaign sends.
            </div>
        </div>
        <button class="cv-btn" type="submit">Save as Draft</button>
    </form>
</div>
