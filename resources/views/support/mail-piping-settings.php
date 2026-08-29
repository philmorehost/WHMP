<?php
/** @var bool $enabled */
/** @var string $host */
/** @var string $port */
/** @var string $encryption */
/** @var string $username */
/** @var bool $validate_cert */
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
        <div class="cv-field">
            <label style="display:flex;align-items:center;gap:var(--cv-space-1);">
                <input type="checkbox" name="validate_cert" value="1" <?= $validate_cert ? 'checked' : '' ?>> Validate SSL certificate
            </label>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin:4px 0 0;">
                Leave unchecked on cPanel/shared hosting — many mail servers present an untrusted certificate, which makes IMAP report
                "[CLOSED] IMAP connection broken (authenticate)" even with correct credentials. Unchecking skips certificate validation.
            </p>
        </div>
        <button class="cv-btn" type="submit">Save</button>
    </form>

    <hr style="border:none;border-top:1px solid var(--cv-border-default);margin:var(--cv-space-4) 0;">

    <div>
        <button class="cv-btn" type="button" id="mailpiping-test" data-token="<?= e(csrf_token()) ?>">🔌 Test Connection</button>
        <span id="mailpiping-test-result" style="margin-left:var(--cv-space-2);font-size:var(--cv-text-sm);"></span>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:6px;">Tests the <em>saved</em> settings — save the form first, then click Test.</p>
    </div>
</div>
