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
// property id; ResellerChat owns that precedence and every validation.

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

    if (ResellerChat::provider($store) !== ResellerChat::PROVIDER_WHATSAPP) {
        // Nothing configured. Deliberately NOT the platform's chat.
        return;
    }

    $digits = (string) $store['support_whatsapp'];
    $greeting = $brand === '' ? 'Hello! I have a question.' : 'Hi ' . $brand . '! I have a question.';
    $colour = trim((string) ($store['primary_color'] ?? ''));

    $style = 'position:fixed;right:1rem;bottom:1rem;z-index:60;display:flex;align-items:center;'
        . 'gap:.5rem;padding:.7rem 1rem;border-radius:999px;text-decoration:none;'
        . 'font-weight:600;font-size:.9rem;box-shadow:0 6px 20px rgba(0,0,0,.25);'
        . 'background:' . ($colour !== '' && \CodeVault\Theme\ThemeSettings::isValidHex($colour) ? $colour : '#25D366') . ';'
        // Text colour is fixed rather than inherited: the button's background is
        // chosen by the reseller, so an inherited colour could land white-on-white.
        . 'color:#fff;';
    ?>
    <a href="<?= e(ResellerChat::whatsappUrl($digits, $greeting)) ?>"
       style="<?= e($style) ?>"
       target="_blank" rel="noopener noreferrer"
       aria-label="Chat with us on WhatsApp">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.32 4.96L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.2 0 4.27.86 5.82 2.42a8.2 8.2 0 0 1 2.42 5.82c0 4.54-3.7 8.24-8.24 8.24a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24Zm-2.6 4.02c-.15 0-.4.06-.61.29-.21.23-.8.78-.8 1.9 0 1.12.82 2.2.93 2.35.11.15 1.58 2.51 3.9 3.42 1.93.76 2.32.61 2.74.57.42-.04 1.35-.55 1.54-1.09.19-.53.19-.99.13-1.09-.06-.1-.21-.15-.44-.27-.23-.11-1.35-.67-1.56-.74-.21-.08-.36-.11-.51.11-.15.23-.59.74-.72.9-.13.15-.27.17-.5.06-.23-.11-.97-.36-1.85-1.14-.68-.61-1.14-1.36-1.27-1.59-.13-.23-.01-.35.1-.46.1-.1.23-.27.34-.4.11-.13.15-.23.23-.38.08-.15.04-.29-.02-.4-.06-.11-.5-1.23-.69-1.68-.16-.38-.33-.39-.46-.4h-.4Z"/>
        </svg>
        <span>WhatsApp us</span>
    </a>
    <?php
} catch (\Throwable) {
    // No container (installer, CLI bootstrap) or a half-migrated schema. A missing
    // chat widget is never worth breaking a page over — and rendering NOTHING is the
    // safe direction to fail in here, because the fallback would be the platform's
    // chatbox on a page that may well be a store's.
}
