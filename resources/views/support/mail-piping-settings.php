<?php
/** @var bool $enabled */
/** @var string $host */
/** @var string $port */
/** @var string $encryption */
/** @var string $username */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Mail Piping</h1>
    <p><a href="/admin/tickets">&larr; Back to tickets</a></p>
    <p style="color:var(--cv-text-secondary);">Incoming emails to this mailbox become tickets (or replies to an existing ticket when the subject contains "[Ticket #N]"). A cron sweep checks the mailbox every 5 minutes.</p>
</div>

<div class="cv-card">
    <form method="post" action="/admin/mail-piping"><?= csrf_field() ?>
        <label style="display:flex;align-items:center;gap:var(--cv-space-1);margin-bottom:var(--cv-space-3);">
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Enable mail piping
        </label>
        <div class="cv-field">
            <label class="cv-label">IMAP Host</label>
            <input class="cv-input" name="host" value="<?= e($host) ?>" placeholder="imap.example.com">
        </div>
        <div class="cv-field">
            <label class="cv-label">Port</label>
            <input class="cv-input" type="number" name="port" value="<?= e($port) ?>" style="width:8rem;">
        </div>
        <div class="cv-field">
            <label class="cv-label">Encryption</label>
            <select class="cv-input" name="encryption">
                <option value="ssl" <?= $encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="tls" <?= $encryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="none" <?= $encryption === 'none' ? 'selected' : '' ?>>None</option>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Username</label>
            <input class="cv-input" name="username" value="<?= e($username) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label">Password (leave blank to keep current)</label>
            <input class="cv-input" type="password" name="password">
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>
</div>
