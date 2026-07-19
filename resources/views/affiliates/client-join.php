<?php
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Affiliate Area</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>
    <p>Earn commission for every client you refer to us. Join the affiliate program to get your own referral link.</p>
    <form method="post" action="/client/affiliate/join"><?= csrf_field() ?>
        <button class="cv-btn" type="submit">Join the Affiliate Program</button>
    </form>
</div>
