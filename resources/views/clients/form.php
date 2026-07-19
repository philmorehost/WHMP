<?php
/** @var array<string, mixed>|null $client */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, array<string, mixed>> $customFields */
/** @var array<int, string> $customFieldValues */
/** @var string|null $error */
$isEdit = $client !== null;
$action = $isEdit ? "/admin/clients/{$client['id']}" : '/admin/clients';
$val = fn (string $key, string $default = '') => e((string) ($client[$key] ?? $default));
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Client' : 'Add Client' ?></h1>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
</div>

<div class="cv-card">
    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">First Name</label>
                <input class="cv-input" name="first_name" value="<?= $val('first_name') ?>" required>
            </div>
            <div class="cv-field">
                <label class="cv-label">Last Name</label>
                <input class="cv-input" name="last_name" value="<?= $val('last_name') ?>" required>
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" value="<?= $val('email') ?>" required>
        </div>
        <?php if (!$isEdit): ?>
        <div class="cv-field">
            <label class="cv-label">Password (leave blank to auto-generate)</label>
            <input class="cv-input" type="password" name="password">
        </div>
        <?php endif; ?>
        <div class="cv-field">
            <label class="cv-label">Company Name</label>
            <input class="cv-input" name="company_name" value="<?= $val('company_name') ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Address</label>
            <input class="cv-input" name="address1" value="<?= $val('address1') ?>" placeholder="Address line 1" style="margin-bottom:var(--cv-space-2);">
            <input class="cv-input" name="address2" value="<?= $val('address2') ?>" placeholder="Address line 2">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">City</label>
                <input class="cv-input" name="city" value="<?= $val('city') ?>">
            </div>
            <div class="cv-field">
                <label class="cv-label">State</label>
                <input class="cv-input" name="state" value="<?= $val('state') ?>">
            </div>
            <div class="cv-field">
                <label class="cv-label">Postcode</label>
                <input class="cv-input" name="postcode" value="<?= $val('postcode') ?>">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">Country (ISO-2)</label>
                <input class="cv-input" name="country" maxlength="2" value="<?= $val('country') ?>">
            </div>
            <div class="cv-field">
                <label class="cv-label">Phone</label>
                <input class="cv-input" name="phone" value="<?= $val('phone') ?>">
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label">VAT Number</label>
            <input class="cv-input" name="vat_number" value="<?= $val('vat_number') ?>" placeholder="e.g. DE123456789">
            <?php if ($isEdit && !empty($client['vat_verified_at'])): ?>
                <p style="margin:var(--cv-space-1) 0 0;font-size:var(--cv-text-xs);">
                    <?php if ((int) $client['vat_verified_valid'] === 1): ?>
                        <span class="cv-badge cv-badge--success">VIES verified</span>
                        <?php if (!empty($client['vat_verified_name'])): ?> as "<?= e((string) $client['vat_verified_name']) ?>"<?php endif; ?>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">VIES rejected</span>
                    <?php endif; ?>
                    on <?= e((string) $client['vat_verified_at']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="cv-field">
            <label class="cv-label">Client Group</label>
            <select class="cv-select" name="client_group_id">
                <option value="">None</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>" <?= ($client['client_group_id'] ?? null) == $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($isEdit): ?>
        <div class="cv-field">
            <label class="cv-label">Status</label>
            <select class="cv-select" name="status">
                <option value="active" <?= ($client['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($client['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="closed" <?= ($client['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
            </select>
        </div>
        <?php endif; ?>
        <div class="cv-field">
            <label class="cv-label">Notes</label>
            <textarea class="cv-textarea" name="notes" rows="3"><?= $val('notes') ?></textarea>
        </div>

        <?php if ($customFields !== []): ?>
            <h3>Custom Fields</h3>
            <?php foreach ($customFields as $cf): ?>
                <?php
                $fieldId = (int) $cf['id'];
                $current = $customFieldValues[$fieldId] ?? '';
                $inputName = "custom_fields[{$fieldId}]";
                ?>
                <div class="cv-field">
                    <label class="cv-label"><?= e($cf['name']) ?><?= $cf['required'] ? ' *' : '' ?></label>
                    <?php if ($cf['type'] === 'textarea'): ?>
                        <textarea class="cv-textarea" name="<?= $inputName ?>" <?= $cf['required'] ? 'required' : '' ?>><?= e($current) ?></textarea>
                    <?php elseif ($cf['type'] === 'dropdown'): ?>
                        <select class="cv-select" name="<?= $inputName ?>">
                            <?php foreach (array_filter(array_map('trim', explode("\n", (string) ($cf['options'] ?? '')))) as $option): ?>
                                <option value="<?= e($option) ?>" <?= $current === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($cf['type'] === 'checkbox'): ?>
                        <input type="checkbox" name="<?= $inputName ?>" value="1" <?= $current === '1' ? 'checked' : '' ?>>
                    <?php elseif ($cf['type'] === 'password'): ?>
                        <input class="cv-input" type="password" name="<?= $inputName ?>">
                    <?php else: ?>
                        <input class="cv-input" type="text" name="<?= $inputName ?>" value="<?= e($current) ?>" <?= $cf['required'] ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Client' ?></button>
    </form>

    <?php if ($isEdit): ?>
        <form method="post" action="/admin/clients/<?= (int) $client['id'] ?>/verify-vat" style="margin-top:var(--cv-space-4);"><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit">Verify VAT Number via VIES</button>
        </form>
    <?php endif; ?>
</div>
