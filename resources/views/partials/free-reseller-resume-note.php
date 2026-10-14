<?php
/**
 * On the sign-in and sign-up pages: "your Free Reseller application is waiting".
 * Shown only on the main website while a guest's draft application is in the
 * session (FreeResellerController::apply); ClientAuthController sends them back
 * to finish it as soon as they are signed in.
 */
use CodeVault\Reseller\FreeResellerProgramme;

if (!is_array($_SESSION[FreeResellerProgramme::DRAFT_SESSION_KEY] ?? null) || current_storefront() !== null) {
    return;
}

$frDraftName = trim((string) ($_SESSION[FreeResellerProgramme::DRAFT_SESSION_KEY]['store_name'] ?? ''));
?>
<div role="status" style="margin-bottom:var(--cv-space-4);padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.45);background:rgba(34,197,94,.08);font-size:var(--cv-text-sm);color:var(--cv-text-primary);text-align:left;">
    <strong>🎁 Your Free Reseller application<?= $frDraftName !== '' ? ' for “' . e($frDraftName) . '”' : '' ?> is saved.</strong>
    Sign in or create your account here and you will go straight back to finish it.
    <a href="/free-reseller/apply" style="color:var(--cv-color-brand-500);font-weight:700;">Edit answers</a>
</div>
