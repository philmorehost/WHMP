<?php
/**
 * The Free Reseller card on a client's dashboard — DIRECT platform clients only,
 * on the main website, who do not already run a store
 * (FreeResellerProgramme::advertFor). Dismissible for 30 days in this browser.
 */
use CodeVault\Reseller\FreeResellerProgramme;

$frAdvert = FreeResellerProgramme::advertFor(FreeResellerProgramme::PLACEMENT_CLIENT);

if ($frAdvert === null || $frAdvert['client'] === null) {
    return;
}
?>
<div class="fr-advert cv-no-print" data-fr-advert>
    <div class="fr-advert__icon" aria-hidden="true">🎁</div>
    <div class="fr-advert__body">
        <div class="fr-advert__label">NEW · FREE RESELLER PROGRAMME</div>
        <h2 class="fr-advert__title">Turn your account into a hosting business — free</h2>
        <p class="fr-advert__text">Get your own branded website and sell our VPS, dedicated servers, domains and hosting at your own prices. We handle the servers and support; you keep the profit.</p>
    </div>
    <div class="fr-advert__actions">
        <a class="fr-advert__btn" href="/free-reseller">Learn more</a>
        <a class="fr-advert__btn fr-advert__btn--light" href="/free-reseller/apply">Apply free</a>
    </div>
    <button type="button" class="fr-advert__close" data-fr-advert-close aria-label="Hide this advert">&times;</button>
</div>
<script>
(function () {
    var card = document.querySelector('[data-fr-advert]');
    if (!card) { return; }
    try {
        var until = parseInt(localStorage.getItem('frAdvertHiddenUntil') || '0', 10);
        if (until > Date.now()) { card.parentNode.removeChild(card); return; }
    } catch (e) {}
    card.querySelector('[data-fr-advert-close]').addEventListener('click', function () {
        try { localStorage.setItem('frAdvertHiddenUntil', String(Date.now() + 30 * 864e5)); } catch (e) {}
        card.parentNode.removeChild(card);
    });
})();
</script>
<style>
.fr-advert { position: relative; display: flex; align-items: center; gap: 20px; margin: 0 0 24px; padding: 22px 26px; border-radius: 16px; color: #fff; background: radial-gradient(500px 200px at 95% 0%, rgba(34,197,94,.35), transparent 70%), linear-gradient(135deg, #0b1026, #14213d); box-shadow: 0 14px 30px rgba(11,16,38,.2); }
.fr-advert__icon { font-size: 2.2rem; width: 60px; height: 60px; border-radius: 16px; background: rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.fr-advert__body { flex: 1; min-width: 0; }
.fr-advert__label { font-size: .7rem; font-weight: 800; letter-spacing: .1em; color: #86efac; }
.fr-advert__title { margin: 4px 0 6px; font-size: 1.2rem; font-weight: 800; color: #fff; line-height: 1.25; }
.fr-advert__text { margin: 0; font-size: .9rem; color: rgba(255,255,255,.8); }
.fr-advert__actions { display: flex; gap: 10px; flex-shrink: 0; margin-right: 18px; }
.fr-advert__btn { padding: 11px 18px; border-radius: 10px; font-weight: 800; font-size: .88rem; text-decoration: none !important; background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff !important; white-space: nowrap; }
.fr-advert__btn--light { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); }
.fr-advert__btn:hover { filter: brightness(1.08); }
.fr-advert__close { position: absolute; top: 8px; right: 10px; background: none; border: 0; color: rgba(255,255,255,.7); font-size: 1.4rem; line-height: 1; cursor: pointer; padding: 4px 8px; }
.fr-advert__close:hover { color: #fff; }
@media (max-width: 760px) { .fr-advert { flex-direction: column; align-items: flex-start; padding: 22px 18px; } .fr-advert__actions { margin-right: 0; width: 100%; } .fr-advert__btn { flex: 1; text-align: center; } }
</style>
