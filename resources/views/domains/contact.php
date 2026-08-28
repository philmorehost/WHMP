<?php
/** @var array<string, mixed> $domain */
/** @var array<string, mixed> $contact */
/** @var array<string, mixed> $formContact */
/** @var string|null $fetchError */
/** @var string $contactSource */
/** @var string|null $notice */
/** @var int $contactId */
/** @var array<int, array<string, mixed>> $clientContacts */
/** @var array<string, mixed> $clientAccountContact */
/** @var string $msg */
/** @var string $saveError */
$id = (int) $domain['id'];
// Registrar field names differ, so the display table uses the raw $contact
// while the editor pre-fills from the normalised $formContact copy.
$formContact = $formContact ?? $contact;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Contact Info — <?= e($domain['domain_name']) ?></h1>
    <p><a href="/admin/domains/<?= $id ?>">&larr; Back to domain</a></p>
</div>

<?php if ($msg !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($saveError !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e($saveError) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Current Contact on File</h2>
    <?php if ($contactSource === 'local'): ?>
        <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-3);">
            Showing the locally stored contact. <?= $notice !== null ? e($notice) : 'The registrar could not be reached.' ?>
            Changes you save are kept locally but may not reach the registrar until the domain is in your reseller account.
        </div>
    <?php endif; ?>
    <?php if ($fetchError !== null): ?>
        <div class="cv-field-error"><?= e($fetchError) ?></div>
    <?php elseif ($contact === []): ?>
        <p style="color:var(--cv-text-secondary);">No contact details on file yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <tbody>
            <?php foreach ($contact as $key => $value): ?>
                <?php if (is_array($value)): continue; endif; ?>
                <tr>
                    <td style="font-weight:600;width:12rem;"><?= e((string) $key) ?></td>
                    <td><?= e((string) $value) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Save Contact Info</h2>
    <div class="cv-field" style="max-width:40rem; margin-bottom:var(--cv-space-4);">
        <label class="cv-label" style="display:block; margin-bottom:6px; font-weight:600;">
            Registrant — on whose behalf is this domain registered?
        </label>
        <select class="cv-select" name="contact_id" data-contact-source form="contact-form" style="width:100%;">
            <option value="" <?= $contactId > 0 || $contactId === -1 ? '' : 'selected' ?>>Custom contact (enter below)</option>
            <option value="-1" <?= $contactId === -1 ? 'selected' : '' ?>>Client account details</option>
            <?php foreach ($clientContacts as $cc): ?>
                <option value="<?= (int) $cc['id'] ?>" <?= $contactId === (int) $cc['id'] ? 'selected' : '' ?>>
                    <?= e((string) $cc['name']) ?> &lt;<?= e((string) $cc['email']) ?>&gt;
                </option>
            <?php endforeach; ?>
        </select>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:6px;">
            Pick a saved contact when the domain belongs to a company/third party. Choose "Custom contact" to type one here.
        </p>
    </div>
    <form id="contact-form" method="post" action="/admin/domains/<?= $id ?>/contact" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);max-width:40rem;" data-contact-custom><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Full Name</label>
            <input class="cv-input" name="name" value="<?= e((string) ($formContact['name'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" value="<?= e((string) ($formContact['email'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Company Name</label>
            <input class="cv-input" name="company_name" value="<?= e((string) ($formContact['company_name'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Phone</label>
            <input class="cv-input" name="phone" value="<?= e((string) ($formContact['phone'] ?? '')) ?>">
        </div>
        <div class="cv-field" style="grid-column:span 2;">
            <label class="cv-label">Address</label>
            <input class="cv-input" name="address1" value="<?= e((string) ($formContact['address1'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">City</label>
            <input class="cv-input" name="city" value="<?= e((string) ($formContact['city'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">State</label>
            <input class="cv-input" name="state" value="<?= e((string) ($formContact['state'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Postcode</label>
            <input class="cv-input" name="postcode" value="<?= e((string) ($formContact['postcode'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Country</label>
            <input class="cv-input" name="country" value="<?= e((string) ($formContact['country'] ?? '')) ?>" placeholder="US">
        </div>
        <button class="cv-btn" type="submit" style="grid-column:span 2;">Save Contact Info</button>
    </form>
</div>
