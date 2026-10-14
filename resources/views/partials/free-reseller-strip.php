<?php
/**
 * "Get Free Reseller" strip across the top of the MAIN website's public pages.
 *
 * Decided by FreeResellerProgramme::advertFor(): never on a reseller's store, never
 * to a store's customer or to someone who already resells. Also left off the
 * programme's own pages and the client area (direct clients get the dashboard card
 * instead). Dismissible for a week, remembered in the browser only.
 */
use CodeVault\Reseller\FreeResellerProgramme;

$frPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (str_starts_with($frPath, '/free-reseller') || str_starts_with($frPath, '/client/') || str_starts_with($frPath, '/admin')) {
    return;
}

$frAdvert = FreeResellerProgramme::advertFor(FreeResellerProgramme::PLACEMENT_PUBLIC);

if ($frAdvert === null) {
    return;
}
?>
<div class="fr-strip cv-no-print" data-fr-strip role="region" aria-label="Free Reseller programme">
    <a class="fr-strip__link" href="/free-reseller">
        <span class="fr-strip__badge">FREE</span>
        <span class="fr-strip__text"><?= e($frAdvert['text']) ?></span>
        <span class="fr-strip__cta">Get Free Reseller &rarr;</span>
    </a>
    <button type="button" class="fr-strip__close" data-fr-strip-close aria-label="Hide this message">&times;</button>
</div>
<script>
(function () {
    var strip = document.querySelector('[data-fr-strip]');
    if (!strip) { return; }
    try {
        var until = parseInt(localStorage.getItem('frStripHiddenUntil') || '0', 10);
        if (until > Date.now()) { strip.parentNode.removeChild(strip); return; }
    } catch (e) {}
    strip.querySelector('[data-fr-strip-close]').addEventListener('click', function () {
        try { localStorage.setItem('frStripHiddenUntil', String(Date.now() + 7 * 864e5)); } catch (e) {}
        strip.parentNode.removeChild(strip);
    });
})();
</script>
<style>
.fr-strip { position: relative; display: flex; align-items: center; justify-content: center; background: linear-gradient(90deg, #0b1026, #14532d 55%, #0b1026); color: #fff; font-size: .9rem; padding: 0 44px; }
.fr-strip__link { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 6px 12px; padding: 10px 0; color: #fff !important; text-decoration: none !important; text-align: center; }
.fr-strip__badge { background: #22c55e; color: #052e16; font-weight: 800; font-size: .7rem; letter-spacing: .08em; padding: 3px 8px; border-radius: 999px; }
.fr-strip__text { font-weight: 600; }
.fr-strip__cta { font-weight: 800; color: #86efac; white-space: nowrap; }
.fr-strip__link:hover .fr-strip__cta { text-decoration: underline; }
.fr-strip__close { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: 0; color: rgba(255,255,255,.75); font-size: 1.4rem; line-height: 1; cursor: pointer; padding: 4px 8px; }
.fr-strip__close:hover { color: #fff; }
@media (max-width: 640px) { .fr-strip { font-size: .82rem; padding: 0 36px 0 12px; } }
</style>
