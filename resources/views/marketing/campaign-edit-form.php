<?php
/**
 * Edit forms for an existing campaign, shared by the draft, paused and sent
 * states on the campaign page.
 *
 * @var array<string, mixed> $campaign
 * @var array<int, array<string, mixed>> $groups
 * @var array<int, array<string, mixed>> $clients
 * @var array<int, array<string, mixed>> $products
 * @var bool $audienceEditable true when targeting can be rewritten here —
 *      drafts and sent campaigns. A paused campaign is mid-send: its pending
 *      recipients were resolved when it was queued, so an audience change
 *      would silently not affect them and the form is hidden rather than
 *      misleading.
 */
$id = (int) $campaign['id'];

// Which targeting the campaign currently stores, as the create form's
// six-way select expresses it.
if (!empty($campaign['only_inactive'])) {
    $targetType = 'inactive';
} elseif (!empty($campaign['client_id'])) {
    $targetType = 'individual';
} elseif (trim((string) ($campaign['product_ids'] ?? '')) !== '') {
    $targetType = 'products';
} elseif (trim((string) ($campaign['external_emails'] ?? '')) !== '' && empty($campaign['client_group_id'])) {
    $targetType = 'external';
} elseif (!empty($campaign['client_group_id'])) {
    $targetType = 'group';
} else {
    $targetType = 'all';
}

$selectedProductIds = array_map('intval', explode(',', (string) ($campaign['product_ids'] ?? '')));
$fieldStyle = static fn (string $type): string => $targetType === $type ? 'display:block;' : 'display:none;';
?>
<details id="edit" style="margin-bottom:var(--cv-space-3);">
    <summary style="cursor:pointer;font-weight:600;">✏️ Edit subject &amp; body</summary>
    <form method="post" action="/admin/campaigns/<?= $id ?>/update" style="margin-top:var(--cv-space-2);">
        <?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" value="<?= e((string) $campaign['subject']) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body (Plain Text or HTML)</label>
            <textarea class="cv-input" name="body" rows="8" required><?= e((string) $campaign['body']) ?></textarea>
            <?= $view->partial('marketing.campaign-merge-fields') ?>
        </div>
        <?php if ($campaign['status'] === 'sent'): ?>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-bottom:var(--cv-space-2);">
                Everyone already sent this campaign keeps the version they received — the edited content goes to
                recipients of the <strong>next</strong> send (⏭️ Send Again above).
            </div>
        <?php elseif ($campaign['status'] === 'paused'): ?>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-bottom:var(--cv-space-2);">
                Recipients still pending get this version when the campaign resumes; anyone already sent keeps what they got.
            </div>
        <?php endif; ?>
        <button class="cv-btn" type="submit">Save Changes</button>
    </form>
</details>

<?php if ($audienceEditable): ?>
<details style="margin-bottom:var(--cv-space-3);">
    <summary style="cursor:pointer;font-weight:600;">🎯 Edit audience</summary>
    <?php if ($campaign['status'] === 'sent'): ?>
        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin:var(--cv-space-2) 0;">
            Takes effect the next time this campaign is sent — people who already received it are counted by client
            id / address, whichever way it reached them.
        </div>
    <?php endif; ?>
    <form method="post" action="/admin/campaigns/<?= $id ?>/update" style="margin-top:var(--cv-space-2);">
        <?= csrf_field() ?>
        <input type="hidden" name="edit_audience" value="1">
        <div class="cv-field">
            <label class="cv-label">Audience Target</label>
            <select class="cv-select" name="target_type" id="campaign-target-type">
                <option value="all"<?= $targetType === 'all' ? ' selected' : '' ?>>🌐 All active clients</option>
                <option value="inactive"<?= $targetType === 'inactive' ? ' selected' : '' ?>>📭 Accounts with no active product/domain</option>
                <option value="group"<?= $targetType === 'group' ? ' selected' : '' ?>>📁 Client Group</option>
                <option value="individual"<?= $targetType === 'individual' ? ' selected' : '' ?>>👤 Individual Client</option>
                <option value="products"<?= $targetType === 'products' ? ' selected' : '' ?>>📦 Clients with specific products</option>
                <option value="external"<?= $targetType === 'external' ? ' selected' : '' ?>>✉️ External email addresses</option>
            </select>
        </div>
        <div class="cv-field" id="target-products-field" style="<?= $fieldStyle('products') ?>">
            <label class="cv-label">Products (select one or more — ctrl/cmd-click for multiple)</label>
            <?php if (!empty($products)): ?>
                <select class="cv-select" name="product_ids[]" multiple size="<?= min(8, max(3, count($products))) ?>">
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"<?= in_array((int) $product['id'], $selectedProductIds, true) ? ' selected' : '' ?>>
                            <?= e(trim((string) ($product['group_name'] ?? '')) !== '' ? $product['group_name'] . ' — ' . $product['name'] : $product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">No products exist yet — create one under Products first.</div>
            <?php endif; ?>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                Emails every active client who currently holds an active or suspended service of any selected product.
                The recipient list is resolved at send time, so it stays current.
            </div>
        </div>
        <div class="cv-field" id="target-inactive-field" style="<?= $fieldStyle('inactive') ?>">
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">
                Emails every <strong>active</strong> account that currently has no service and no domain in an
                <strong>active</strong> state. The recipient list is resolved at send time, so it stays current.
            </div>
        </div>
        <div class="cv-field" id="target-external-field" style="<?= $fieldStyle('external') ?>">
            <label class="cv-label">External Email Addresses</label>
            <textarea class="cv-input" name="external_emails" rows="5"
                      placeholder="press@example.com, partner@example.com&#10;prospect@example.com"
                      style="font-family:monospace;font-size:.85rem;"><?= e((string) ($campaign['external_emails'] ?? '')) ?></textarea>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">
                Separate with commas, semicolons or one per line. Invalid addresses are ignored, duplicates removed.
            </div>
        </div>
        <div class="cv-field" id="target-group-field" style="<?= $fieldStyle('group') ?>">
            <label class="cv-label">Target Client Group</label>
            <select class="cv-select" name="client_group_id">
                <option value="">— Select Group —</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>"<?= (int) ($campaign['client_group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field" id="target-individual-field" style="<?= $fieldStyle('individual') ?>">
            <label class="cv-label">Target Individual Client</label>
            <select class="cv-select" name="client_id">
                <option value="">— Select Client —</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>"<?= (int) ($campaign['client_id'] ?? 0) === (int) $client['id'] ? ' selected' : '' ?>><?= e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="cv-btn" type="submit">Save Audience</button>
    </form>
</details>
<?php endif; ?>
