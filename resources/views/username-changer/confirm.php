<?php
/**
 * Standalone confirmation page for the emailed link. Works signed out and renders
 * in the chrome of the site it was opened on (a store's link only works on the
 * store). GET shows a button; only the POST confirms.
 *
 * @var string $token
 * @var array<string, mixed>|null $request
 * @var bool $expired
 * @var array<string, mixed>|null $result
 * @var string $heading
 */
?>
<link rel="stylesheet" href="/assets/css/username-changer.css">
<div class="ucn" style="max-width:560px;margin:40px auto;">
    <div class="ucn-modal__dialog" style="position:static;opacity:1;transform:none;width:100%;max-height:none;">
        <h1 class="ucn-modal__title"><?= e($heading) ?></h1>

        <?php if ($result !== null): ?>
            <div class="ucn-flash ucn-flash--<?= !empty($result['ok']) ? 'ok' : 'bad' ?>" style="margin-top:14px;"><?= e((string) $result['message']) ?></div>
            <?php if (!empty($result['ok']) && $request !== null): ?>
                <p class="ucn-muted">You can follow the request on <a href="/client/services/<?= (int) $request['service_id'] ?>">your service page</a>.</p>
            <?php endif; ?>
        <?php elseif ($request === null): ?>
            <div class="ucn-flash ucn-flash--bad" style="margin-top:14px;">This confirmation link is invalid or has already been used.</div>
        <?php elseif ($expired): ?>
            <div class="ucn-flash ucn-flash--bad" style="margin-top:14px;">This confirmation link has expired. Please start a new request from your service page.</div>
        <?php else: ?>
            <p style="margin-top:14px;">Please confirm the cPanel username change for <strong><?= e((string) ($request['domain'] ?? '')) ?></strong>:</p>
            <p style="font-size:1.15rem;font-family:var(--cv-font-mono, ui-monospace, monospace);">
                <?= e((string) $request['old_username']) ?> → <strong><?= e((string) $request['new_username']) ?></strong>
            </p>
            <ul class="ucn-muted">
                <li>Your cPanel, FTP and SSH logins will use the new name. Your password does not change.</li>
                <li>Your home folder becomes <code>/home/<?= e((string) $request['new_username']) ?></code>.</li>
                <li><?= (int) ($request['rename_db_objects'] ?? 0) === 1 ? 'Databases will be renamed to the new prefix — update your site configuration afterwards.' : 'Databases keep their current names.' ?></li>
            </ul>
            <form method="post" action="/username-change/confirm/<?= e($token) ?>" class="ucn-actions" style="justify-content:flex-start;">
                <?= csrf_field() ?>
                <button class="ucn-btn ucn-btn--primary" type="submit">Confirm change</button>
            </form>
            <p class="ucn-muted">Didn't ask for this? Just close this page — nothing changes unless you confirm.</p>
        <?php endif; ?>
    </div>
</div>
