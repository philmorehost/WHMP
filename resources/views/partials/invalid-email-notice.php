<?php
/**
 * Client-area banner: "we can't email you — please update your address".
 * Shown by the Invalid Email Blocker addon to a signed-in client whose current
 * email the Email Validation scan marked invalid. See invalid_email_notice().
 *
 * @var array{email: string, reason: string, blocking: bool, onProfile: bool}|null $notice
 */
$notice ??= invalid_email_notice();

if ($notice === null) {
    return;
}

$returnPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/client'), PHP_URL_PATH);
?>
<div class="ieb-notice cv-no-print" role="alert" aria-labelledby="ieb-notice-title">
    <div class="ieb-notice__icon" aria-hidden="true">✉️</div>
    <div class="ieb-notice__body">
        <strong class="ieb-notice__title" id="ieb-notice-title">Please update your email address</strong>
        <p class="ieb-notice__text">
            We can't deliver email to <strong><?= e($notice['email']) ?></strong> — <?= e($notice['reason']) ?>.
            <?php if ($notice['blocking']): ?>
                Until it's updated, your invoices, payment receipts and service notices are only shown in your
                <a href="/client/notifications">notifications</a> here, not emailed.
            <?php else: ?>
                You may be missing invoices, payment receipts and important service notices.
            <?php endif; ?>
            <?php if ($notice['onProfile']): ?>
                Enter a working address in the <a href="#ieb-email">Email</a> field below and save.
            <?php endif; ?>
        </p>
    </div>
    <div class="ieb-notice__actions">
        <?php if (!$notice['onProfile']): ?>
            <a class="cv-btn ieb-notice__cta" href="/client/account#ieb-email">Update email address</a>
        <?php endif; ?>
        <form method="post" action="/client/email-notice/hide">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($returnPath) ?>">
            <button type="submit" class="ieb-notice__later">Remind me tomorrow</button>
        </form>
    </div>
</div>
<style>
.ieb-notice { display: flex; align-items: center; gap: 14px; margin: 0 0 var(--cv-space-4, 16px); padding: 14px 16px;
    border: 1px solid #f5c26b; border-left: 4px solid #f59e0b; border-radius: 12px; background: #fff8eb; color: #5c3b06; }
.ieb-notice__icon { font-size: 26px; line-height: 1; flex: none; }
.ieb-notice__body { flex: 1; min-width: 0; }
.ieb-notice__title { display: block; font-size: 15px; color: #3d2703; margin-bottom: 2px; }
.ieb-notice__text { margin: 0; font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; }
.ieb-notice__text a { color: #92400e; font-weight: 600; text-decoration: underline; }
.ieb-notice__actions { display: flex; flex-direction: column; align-items: stretch; gap: 6px; flex: none; }
.ieb-notice__actions form { margin: 0; }
.ieb-notice__cta { white-space: nowrap; text-align: center; text-decoration: none !important; }
.ieb-notice__later { width: 100%; background: none; border: 0; padding: 4px 6px; font: inherit; font-size: 13px;
    color: #92400e; text-decoration: underline; cursor: pointer; }
@media (max-width: 640px) {
    .ieb-notice { flex-wrap: wrap; align-items: flex-start; }
    .ieb-notice__actions { width: 100%; }
}
</style>
