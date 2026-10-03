<?php

// WHICH chat a public page shows.
//
// This exists because `partials.tawk-widget` is the PLATFORM's chatbox, and it is
// rendered into `layouts.client` — the layout behind every public page, including
// a storefront. So a store's customer was being invited to chat with the platform
// about the platform's prices.
//
// The rule here is one line: if this request matched a store, the store's own chat
// is shown and the platform's is NOT — not as a fallback, not when the store has
// configured nothing. A host that belongs to somebody else never carries our
// chatbox. A store with no chat configured simply has no chat, and its own contact
// page and ticket form are one click away.
//
// Store chat is a WhatsApp button by default and Tawk.To if the reseller has set a
// property id; ResellerChat owns that precedence and every validation. Until a
// reseller has saved chat settings at all, the WhatsApp button uses their account
// phone automatically, so every store has a working chat from day one.

use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerChat;
use CodeVault\Security\SecurityHeaders;
use CodeVault\View;

try {
    $container = \CodeVault\Support\App::container();

    /** @var CurrentReseller $reseller */
    $reseller = $container->make(CurrentReseller::class);

    if (!$reseller->exists()) {
        // The platform's own site: unchanged behaviour, rendered from the one
        // place that has always owned it (the admin layout uses it too).
        echo $container->make(View::class)->partial('partials.tawk-widget');

        return;
    }

    /** @var array<string, mixed> $store */
    $store = (array) $reseller->get();
    $brand = trim(brand_name());

    if (ResellerChat::provider($store) === ResellerChat::PROVIDER_TAWKTO) {
        // The widget will render, so this response's CSP has to allow its origins.
        // Set before SecurityHeaders::apply() runs, which is after dispatch.
        SecurityHeaders::setAllowTawkTo(true);

        // Our own snippet, with the reseller's validated property id interpolated —
        // never a snippet the reseller pasted. ResellerChat refuses anything that is
        // not id-shaped, which is what makes that safe.
        echo SecurityHeaders::stampInlineScripts(
            ResellerChat::tawkEmbed(
                (string) $store['tawk_property_id'],
                (string) ($store['tawk_widget_id'] ?? '')
            )
        );

        return;
    }

    // The owner's account phone is the AUTOMATIC default: until the reseller has
    // saved chat settings, their store shows a WhatsApp button on that number, so a
    // new store has a working chat from its first visitor. Only looked up when it
    // could matter — a saved number, or a saved "no chat", never needs it.
    $ownerPhone = null;

    if (!ResellerChat::isConfigured($store) && trim((string) ($store['support_whatsapp'] ?? '')) === '' && (int) ($store['client_id'] ?? 0) > 0) {
        $owner = $container->make(\CodeVault\Clients\ClientRepository::class)->find((int) $store['client_id']);
        $ownerPhone = $owner === null ? null : (string) ($owner['phone'] ?? '');
    }

    $digits = ResellerChat::whatsappDigitsFor($store, $ownerPhone);

    if ($digits === null) {
        // Nothing configured (or the reseller chose no chat). Deliberately NOT the
        // platform's chat.
        return;
    }

    $greeting = $brand === '' ? 'Hello! I have a question.' : 'Hi ' . $brand . '! I have a question.';
    $colour = trim((string) ($store['primary_color'] ?? ''));
    // Text colour is fixed rather than inherited: the background is chosen by the
    // reseller, so an inherited colour could land white-on-white.
    $accent = $colour !== '' && \CodeVault\Theme\ThemeSettings::isValidHex($colour) ? $colour : '#25D366';
    $waUrl = ResellerChat::whatsappUrl($digits, $greeting);
    $storeKey = (int) ($store['id'] ?? 0);
    $icon = '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.32 4.96L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.2 0 4.27.86 5.82 2.42a8.2 8.2 0 0 1 2.42 5.82c0 4.54-3.7 8.24-8.24 8.24a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24Zm-2.6 4.02c-.15 0-.4.06-.61.29-.21.23-.8.78-.8 1.9 0 1.12.82 2.2.93 2.35.11.15 1.58 2.51 3.9 3.42 1.93.76 2.32.61 2.74.57.42-.04 1.35-.55 1.54-1.09.19-.53.19-.99.13-1.09-.06-.1-.21-.15-.44-.27-.23-.11-1.35-.67-1.56-.74-.21-.08-.36-.11-.51.11-.15.23-.59.74-.72.9-.13.15-.27.17-.5.06-.23-.11-.97-.36-1.85-1.14-.68-.61-1.14-1.36-1.27-1.59-.13-.23-.01-.35.1-.46.1-.1.23-.27.34-.4.11-.13.15-.23.23-.38.08-.15.04-.29-.02-.4-.06-.11-.5-1.23-.69-1.68-.16-.38-.33-.39-.46-.4h-.4Z"/></svg>';

    // THE WIDGET
    //
    // Floats bottom-right, above the footer, on every page of the storefront.
    // - The launcher is a real link to WhatsApp, so with JavaScript off (or
    //   blocked) the visitor still reaches the store in one tap.
    // - With JavaScript, a small greeting card opens by itself once per visit, and
    //   the launcher toggles it.
    // - Closing it MINIMISES the widget to a round icon and remembers that (per
    //   store, in localStorage), so a visitor who dismissed it is not greeted again
    //   on every page; tapping the icon brings it back.
    // - z-index sits under the promo popup (9998) so a store's own offer is never
    //   hidden behind its chat button.
    ?>
    <div class="cv-wa cv-no-print" id="cv-wa-widget" data-cv-wa data-store="<?= $storeKey ?>" style="--cv-wa-accent:<?= e($accent) ?>;">
        <div class="cv-wa__card" id="cv-wa-card" role="dialog" aria-label="Chat with <?= e($brand !== '' ? $brand : 'us') ?> on WhatsApp" data-cv-wa-card hidden>
            <div class="cv-wa__header">
                <span class="cv-wa__avatar"><?= $icon ?></span>
                <span class="cv-wa__title">
                    <strong><?= e($brand !== '' ? $brand : 'Customer support') ?></strong>
                    <span>Chat with us on WhatsApp</span>
                </span>
                <button type="button" class="cv-wa__close" data-cv-wa-minimize aria-label="Minimise chat" title="Minimise">
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" fill="none"/></svg>
                </button>
            </div>
            <div class="cv-wa__body">
                <p class="cv-wa__bubble">Hi there 👋<br>How can we help you today?</p>
            </div>
            <a class="cv-wa__cta" href="<?= e($waUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= $icon ?><span>Start chat</span>
            </a>
        </div>
        <a class="cv-wa__launcher" href="<?= e($waUrl) ?>" target="_blank" rel="noopener noreferrer"
           data-cv-wa-launcher aria-controls="cv-wa-card" aria-expanded="false"
           aria-label="Chat with us on WhatsApp">
            <?= $icon ?>
            <span class="cv-wa__label">Chat with us</span>
        </a>
    </div>
    <style>
    .cv-wa { position: fixed; right: max(16px, env(safe-area-inset-right)); bottom: max(16px, env(safe-area-inset-bottom)); z-index: 9000; display: flex; flex-direction: column; align-items: flex-end; gap: 12px; font-family: inherit; pointer-events: none; }
    .cv-wa > * { pointer-events: auto; }
    .cv-wa__launcher { display: inline-flex; align-items: center; gap: 8px; min-height: 56px; padding: 0 20px 0 16px; border-radius: 999px; background: var(--cv-wa-accent, #25D366); color: #fff !important; text-decoration: none !important; font-weight: 700; font-size: .95rem; box-shadow: 0 8px 24px rgba(0,0,0,.25); transition: transform .15s ease, box-shadow .15s ease, padding .2s ease; }
    .cv-wa__launcher:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,0,0,.3); }
    .cv-wa__launcher:focus-visible, .cv-wa__close:focus-visible, .cv-wa__cta:focus-visible { outline: 3px solid #fff; outline-offset: 2px; box-shadow: 0 0 0 6px rgba(0,0,0,.35); }
    .cv-wa--minimized .cv-wa__launcher { width: 56px; padding: 0; justify-content: center; }
    .cv-wa--minimized .cv-wa__label { display: none; }
    .cv-wa__card { width: min(340px, calc(100vw - 32px)); border-radius: 16px; overflow: hidden; background: var(--cv-bg-surface, #fff); color: var(--cv-text-primary, #111); box-shadow: 0 18px 48px rgba(0,0,0,.28); animation: cv-wa-in .22s ease-out; }
    .cv-wa__card[hidden] { display: none; }
    .cv-wa__header { display: flex; align-items: center; gap: 12px; padding: 14px 14px 14px 16px; background: var(--cv-wa-accent, #25D366); color: #fff; }
    .cv-wa__avatar { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,255,255,.2); }
    .cv-wa__title { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
    .cv-wa__title strong { font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-wa__title span { font-size: .8rem; opacity: .9; }
    .cv-wa__close { flex: 0 0 auto; width: 34px; height: 34px; border: none; border-radius: 50%; background: rgba(0,0,0,.15); color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
    .cv-wa__close:hover { background: rgba(0,0,0,.3); }
    .cv-wa__body { padding: 18px 16px 8px; background: var(--cv-bg-surface-sunken, #f3f4f6); }
    .cv-wa__bubble { margin: 0; display: inline-block; max-width: 90%; padding: 10px 14px; border-radius: 4px 14px 14px 14px; background: var(--cv-bg-surface, #fff); color: var(--cv-text-primary, #111); font-size: .92rem; line-height: 1.45; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
    .cv-wa__cta { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0; padding: 14px 16px; background: #25D366; color: #fff !important; text-decoration: none !important; font-weight: 700; font-size: .95rem; border-top: 1px solid rgba(0,0,0,.06); }
    .cv-wa__cta:hover { filter: brightness(1.06); }
    .cv-wa__cta svg { width: 20px; height: 20px; }
    @keyframes cv-wa-in { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
    @media (max-width: 480px) {
        .cv-wa { right: max(12px, env(safe-area-inset-right)); bottom: max(12px, env(safe-area-inset-bottom)); }
        .cv-wa__launcher { width: 56px; padding: 0; justify-content: center; }
        .cv-wa__label { display: none; }
        .cv-wa__card { width: calc(100vw - 24px); }
    }
    @media (prefers-reduced-motion: reduce) { .cv-wa__card { animation: none; } .cv-wa__launcher { transition: none; } }
    @media print { .cv-wa { display: none !important; } }
    </style>
    <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        var root = document.getElementById('cv-wa-widget');
        if (!root) { return; }
        var card = root.querySelector('[data-cv-wa-card]');
        var launcher = root.querySelector('[data-cv-wa-launcher]');
        var closer = root.querySelector('[data-cv-wa-minimize]');
        if (!card || !launcher || !closer) { return; }

        var key = 'cv-wa-minimized:' + (root.getAttribute('data-store') || '0');
        var greetedKey = key + ':greeted';
        function read(store, k) { try { return window[store].getItem(k); } catch (e) { return null; } }
        function write(store, k, v) { try { if (v === null) { window[store].removeItem(k); } else { window[store].setItem(k, v); } } catch (e) {} }

        function open() {
            card.hidden = false;
            root.classList.remove('cv-wa--minimized');
            launcher.setAttribute('aria-expanded', 'true');
        }
        function minimise(remember) {
            card.hidden = true;
            root.classList.add('cv-wa--minimized');
            launcher.setAttribute('aria-expanded', 'false');
            if (remember) { write('localStorage', key, '1'); }
        }

        launcher.addEventListener('click', function (event) {
            event.preventDefault();
            if (card.hidden) {
                open();
                write('localStorage', key, null);
            } else {
                minimise(true);
            }
        });
        closer.addEventListener('click', function () {
            minimise(true);
            launcher.focus();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !card.hidden) { minimise(true); }
        });

        if (read('localStorage', key) === '1') {
            minimise(false);
            return;
        }

        // Shown automatically: greet once per visit, a moment after the page loads
        // so it does not compete with the page (or the store's promo popup).
        if (read('sessionStorage', greetedKey) !== '1') {
            window.setTimeout(function () {
                if (read('localStorage', key) === '1') { return; }
                var promo = document.querySelector('[data-promo-banner]:not([hidden])');
                if (promo) { return; }
                open();
                write('sessionStorage', greetedKey, '1');
            }, 1500);
        }
    })();
    </script>
    <?php
} catch (\Throwable) {
    // No container (installer, CLI bootstrap) or a half-migrated schema. A missing
    // chat widget is never worth breaking a page over — and rendering NOTHING is the
    // safe direction to fail in here, because the fallback would be the platform's
    // chatbox on a page that may well be a store's.
}
