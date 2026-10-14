<?php
/**
 * The Free Reseller section on the MAIN website's home page. Same audience rule as
 * the strip (FreeResellerProgramme::advertFor) — the home page of a reseller's store
 * is a different view entirely and never includes this.
 */
use CodeVault\Reseller\FreeResellerProgramme;

$frAdvert = FreeResellerProgramme::advertFor(FreeResellerProgramme::PLACEMENT_PUBLIC);

if ($frAdvert === null) {
    return;
}
?>
<section class="fr-promo cv-no-print" aria-label="Free Reseller programme">
    <div class="fr-promo__body">
        <span class="fr-promo__eyebrow">🎁 Free Reseller Programme</span>
        <h2 class="fr-promo__title"><?= e($frAdvert['headline']) ?></h2>
        <p class="fr-promo__text"><?= e($frAdvert['tagline']) ?></p>
        <ul class="fr-promo__ticks">
            <li>✓ Free branded website</li>
            <li>✓ Sell VPS, dedicated, domains &amp; hosting</li>
            <li>✓ Your prices, your profit</li>
            <li>✓ We handle servers &amp; support</li>
        </ul>
    </div>
    <div class="fr-promo__actions">
        <a class="fr-promo__btn" href="/free-reseller">Get Free Reseller &rarr;</a>
        <a class="fr-promo__link" href="/free-reseller#earn">See how much you can earn</a>
    </div>
</section>
<style>
.fr-promo { margin: 28px 0; border-radius: 20px; padding: 32px 36px; display: flex; gap: 28px; align-items: center; justify-content: space-between; color: #fff; background: radial-gradient(600px 240px at 90% 0%, rgba(34,197,94,.35), transparent 70%), linear-gradient(135deg, #0b1026, #14213d); box-shadow: 0 20px 40px rgba(11,16,38,.25); }
.fr-promo__eyebrow { display: inline-block; font-weight: 800; font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: #86efac; }
.fr-promo__title { margin: 8px 0 8px; font-size: clamp(1.4rem, 2.6vw, 1.9rem); font-weight: 800; color: #fff; line-height: 1.2; }
.fr-promo__text { margin: 0; color: rgba(255,255,255,.82); max-width: 640px; }
.fr-promo__ticks { list-style: none; padding: 0; margin: 14px 0 0; display: flex; flex-wrap: wrap; gap: 6px 18px; font-weight: 600; font-size: .9rem; color: rgba(255,255,255,.9); }
.fr-promo__actions { display: flex; flex-direction: column; gap: 10px; align-items: center; flex-shrink: 0; }
.fr-promo__btn { display: inline-block; padding: 14px 26px; border-radius: 12px; background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff !important; font-weight: 800; text-decoration: none !important; box-shadow: 0 10px 24px rgba(22,163,74,.35); white-space: nowrap; }
.fr-promo__btn:hover { filter: brightness(1.06); }
.fr-promo__link { color: rgba(255,255,255,.85) !important; font-size: .88rem; font-weight: 600; }
@media (max-width: 800px) { .fr-promo { flex-direction: column; align-items: flex-start; padding: 26px 22px; } .fr-promo__actions { align-items: stretch; width: 100%; } .fr-promo__btn { text-align: center; } }
</style>
