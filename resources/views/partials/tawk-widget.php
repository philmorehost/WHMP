<?php
// The Tawk.To live-chat widget. Rendered into the client and admin layouts,
// but only when the tawk-to addon is active, a widget code is saved, and the
// current page key is in the addon's saved target list.
use CodeVault\Marketing\TawkToPages;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Security\SecurityHeaders;

try {
    $container = \CodeVault\Support\App::container();
    $repo = $container->make(AddonModuleRepository::class);

    if (!$repo->isActive('tawk-to')) {
        return;
    }

    $config = $repo->getConfig('tawk-to');
    $widgetCode = (string) ($config['widget_code'] ?? '');
    if ($widgetCode === '') {
        return;
    }

    $pages = is_array($config['pages'] ?? null) ? $config['pages'] : [];
    $pageKey = TawkToPages::keyForPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    if (!in_array(TawkToPages::ALL, $pages, true) && !in_array($pageKey, $pages, true)) {
        return;
    }

    // The widget is going to render, so the CSP on this response must allow
    // its origins. The header is applied AFTER dispatch in Kernel::handle()
    // (SecurityHeaders::apply), so setting the flag here is in time.
    SecurityHeaders::setAllowTawkTo(true);

    // The pasted snippet is an inline <script>, and script-src carries no
    // 'unsafe-inline' — stamp the per-request nonce onto inline script tags
    // so the embed is actually allowed to run.
    $nonce = SecurityHeaders::nonce();
    $widgetCode = (string) preg_replace(
        '/<script(?![^>]*\bsrc=)([^>]*)>/i',
        '<script$1 nonce="' . $nonce . '">',
        $widgetCode
    );

    echo $widgetCode;
} catch (\Throwable) {
    // No container/DB yet (installer, CLI bootstrap), or the table is missing
    // mid-migration — a missing widget is never worth breaking the page over.
    return;
}
