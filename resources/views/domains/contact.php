<?php
/** @var array<string, mixed> $domain */
/** @var array<string, mixed> $contact */
/** @var string|null $fetchError */
$id = (int) $domain['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Contact Info — <?= e($domain['domain_name']) ?></h1>
    <p><a href="/admin/domains/<?= $id ?>">&larr; Back to domain</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Current Contact on File</h2>
    <?php if ($fetchError !== null): ?>
        <div class="cv-field-error"><?= e($fetchError) ?></div>
    <?php elseif ($contact === []): ?>
        <p style="color:var(--cv-text-secondary);">No contact details returned by the registrar.</p>
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
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
        Different registrars return contact fields under different names, so this form isn't pre-filled from the section above — fill in the values you want saved.
    </p>
    <form method="post" action="/admin/domains/<?= $id ?>/contact" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);max-width:40rem;"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Full Name</label>
            <input class="cv-input" name="name" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Email</label>
            <input class="cv-input" type="email" name="email" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Company Name</label>
            <input class="cv-input" name="company_name">
        </div>
        <div class="cv-field">
            <label class="cv-label">Phone</label>
            <input class="cv-input" name="phone">
        </div>
        <div class="cv-field" style="grid-column:span 2;">
            <label class="cv-label">Address</label>
            <input class="cv-input" name="address1">
        </div>
        <div class="cv-field">
            <label class="cv-label">City</label>
            <input class="cv-input" name="city">
        </div>
        <div class="cv-field">
            <label class="cv-label">State</label>
            <input class="cv-input" name="state">
        </div>
        <div class="cv-field">
            <label class="cv-label">Postcode</label>
            <input class="cv-input" name="postcode">
        </div>
        <div class="cv-field">
            <label class="cv-label">Country</label>
            <input class="cv-input" name="country" placeholder="US">
        </div>
        <button class="cv-btn" type="submit" style="grid-column:span 2;">Save Contact Info</button>
    </form>
</div>
