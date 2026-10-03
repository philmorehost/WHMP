<?php
/**
 * A sign-in-on-behalf link (ClientImpersonation) that could not be used. Deliberately
 * one message for every reason — expired, used, wrong site, account moved — so the page
 * says nothing about the account to whoever holds the link.
 */
?>
<div class="cv-card" style="max-width:28rem;margin:var(--cv-space-8) auto;">
    <h1 class="cv-card__title">This link can't be used</h1>
    <p style="color:var(--cv-text-secondary);">
        Sign-in links like this one work once, for two minutes, on one website. This one has expired,
        has already been used, or belongs to another website. Go back and open the account again.
    </p>
    <p><a class="cv-btn" href="/client/login">Go to sign in</a></p>
</div>
