<?php
/**
 * The storefront header — every page of a reseller's website.
 *
 * Laid out like a premium hosting template: a slim utility bar (contact, currency,
 * language, account) above a sticky main bar (logo, menu, cart, call to action).
 * The store's product categories live under SERVICES as a mega menu, so the home
 * page no longer has to list the whole catalogue to make it reachable.
 *
 * White-label rules, as for the rest of the storefront:
 *  - no colour is written here; everything comes from storefront.css, driven by
 *    the store's own --cv-color-brand-500/600;
 *  - only categories this store can actually sell are listed (StorefrontCatalogue
 *    skips empty groups and the internal "System" group);
 *  - nothing links to the platform (its affiliate scheme, its terms page, its
 *    knowledgebase) — those belong to a different business.
 *
 * Resolves its own data through the container, like partials/header.php, because
 * layouts.client is shared by every public controller.
 *
 * @var array<string, mixed>|null $store
 * @var bool $home
 * @var array<int, array<string, mixed>>|null $currencies
 * @var array<string, mixed>|null $selectedCurrency
 * @var CodeVault\Localization\Translation|null $t
 * @var array<int, array<string, mixed>>|null $languages
 * @var array<string, mixed>|null $theme
 * @var array<int, array<string, mixed>>|null $categories  injectable for tests
 * @var array<string, mixed>|null $client                  injectable for tests
 * @var int|null $cartCount                                injectable for tests
 * @var callable|null $money                               injectable for tests
 * @var bool|null $platform                                true ONLY from the main-website branch of layouts/client.php
 * @var array<string, string>|null $contact                injectable for tests (platform mode)
 * @var int|null $unreadNotifications                      injectable for tests
 * @var bool|null $freeResellerNav                         injectable for tests (platform mode)
 *
 * Platform mode (`platform => true`) is the MAIN website wearing this chrome. It
 * adds the platform's own extras — company contact details, Knowledgebase,
 * network status, Affiliates, the Free Reseller link — and is never set on a
 * store's host, so none of those can appear on a reseller's website.
 */
use CodeVault\Reseller\StorefrontIcons as Icon;

$store ??= [];
$home ??= false;
$currencies ??= null;
$selectedCurrency ??= null;
$t ??= null;
$languages ??= null;
$theme ??= [];
$categories ??= null;
$client ??= null;
$cartCount ??= null;
$money ??= null;
$platform = (bool) ($platform ?? false);
$contact ??= null;
$unreadNotifications ??= null;
$freeResellerNav ??= null;

// Each lookup stands alone: one missing service (a view rendered without a
// session, say) must not blank the menu or the currency switcher with it.
$resolve = static function (callable $fn) {
    try {
        return $fn(\CodeVault\Support\App::container());
    } catch (\Throwable) {
        return null;
    }
};

$categories ??= $resolve(static fn ($c) => $c->make(\CodeVault\Reseller\StorefrontCatalogue::class)->categories());
$client ??= $resolve(static fn ($c) => $c->make(\CodeVault\Clients\ClientAuthGuard::class)->currentClient());
$cartCount ??= $resolve(static fn ($c) => $c->make(\CodeVault\Cart\Cart::class)->count());

if ($currencies === null || $selectedCurrency === null) {
    $currencies = $resolve(static fn ($c) => $c->make(\CodeVault\Billing\CurrencyRepository::class)->all());
    $selectedCurrency = $resolve(static fn ($c) => $c->make(\CodeVault\Billing\CurrencyService::class)
        ->resolveEffective($client, $c->make(\CodeVault\Billing\CurrencySelection::class)->get()));
}

if ($money === null && $selectedCurrency !== null) {
    $headerCurrency = $selectedCurrency;
    $money = $resolve(static function ($c) use ($headerCurrency) {
        $currencyService = $c->make(\CodeVault\Billing\CurrencyService::class);

        return static fn (float $amount): string => $currencyService->format($amount, $headerCurrency);
    });
}

$categories = (array) ($categories ?? []);
$cartCount = (int) ($cartCount ?? 0);
$money ??= static fn (float $amount): string => number_format($amount, 2);

$brandName = trim((string) ($theme['brandName'] ?? ($store['brand_name'] ?? ''))) ?: brand_name();
$logo = trim((string) ($theme['logoUrl'] ?? ''));

if ($logo !== '' && (str_starts_with($logo, '/assets') || str_starts_with($logo, '/uploads'))) {
    $logo = img($logo, 320);
}

$supportEmail = trim((string) ($store['support_email'] ?? ''));
$whatsapp = '';
$phone = '';

if ($platform) {
    // The platform's own company details — never a store's, never on a store.
    $contact ??= \CodeVault\Theme\PlatformSite::contact();
    $supportEmail = trim((string) ($contact['email'] ?? ''));
    $whatsapp = (string) ($contact['whatsapp'] ?? '');
    $phone = $whatsapp === '' ? trim((string) ($contact['phone'] ?? '')) : '';
    $freeResellerNav ??= \CodeVault\Reseller\FreeResellerProgramme::advertFor(\CodeVault\Reseller\FreeResellerProgramme::PLACEMENT_NAV) !== null;
} else {
    try {
        $ownerPhone = null;

        if (!\CodeVault\Reseller\ResellerChat::isConfigured($store) && (int) ($store['client_id'] ?? 0) > 0) {
            $owner = \CodeVault\Support\App::container()->make(\CodeVault\Clients\ClientRepository::class)->find((int) $store['client_id']);
            $ownerPhone = $owner === null ? null : (string) ($owner['phone'] ?? '');
        }

        $whatsapp = (string) (\CodeVault\Reseller\ResellerChat::whatsappDigitsFor($store, $ownerPhone) ?? '');
    } catch (\Throwable) {
        $whatsapp = (string) (\CodeVault\Reseller\ResellerChat::whatsappDigitsFor($store, null) ?? '');
    }
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$isActive = static function (string $prefix) use ($path): bool {
    return $prefix === '/' ? $path === '/' : str_starts_with($path, $prefix);
};
$cycleShort = static function (?string $cycle): string {
    return match ((string) $cycle) {
        'monthly' => '/mo',
        'quarterly' => '/qtr',
        'semiannually' => '/6mo',
        'annually' => '/yr',
        'biennially' => '/2yr',
        'triennially' => '/3yr',
        default => '',
    };
};
$redirectBack = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$freeResellerNav = $platform && (bool) $freeResellerNav;

if ($client !== null && $unreadNotifications === null) {
    $unreadNotifications = $resolve(static fn ($c) => $c->make(\CodeVault\Notifications\ClientNotificationRepository::class)->unreadCount((int) $client['id']));
}

$unreadNotifications = (int) ($unreadNotifications ?? 0);
$clientName = $client === null ? '' : trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
$clientName = $clientName !== '' ? $clientName : (string) ($client['email'] ?? 'My account');
$clientInitial = strtoupper(mb_substr($clientName, 0, 1)) ?: 'A';

// The signed-in customer's shortcuts. The same generic client-area routes on
// every site; the affiliate scheme is the platform's, so only in platform mode.
$accountLinks = [
    ['/client/dashboard', 'panel', 'Dashboard'],
    ['/client/services', 'server', 'My services'],
    ['/client/domains', 'globe', 'My domains'],
    ['/client/invoices', 'wallet', 'Invoices'],
    ['/client/tickets', 'ticket', 'Support tickets'],
    ['/client/emails', 'mail', 'My emails'],
    ['/client/payment-methods', 'lock', 'Payment methods'],
    ['/client/account', 'user', 'Account settings'],
];

if ($platform) {
    $accountLinks[] = ['/client/affiliate', 'trend', 'Affiliates'];
}
?>
<header class="sf-header<?= $home ? ' sf-header--overlay' : '' ?>" data-sf-header>
    <div class="sf-topbar">
        <div class="sf-container sf-topbar__inner">
            <div class="sf-topbar__contact">
                <?php if ($supportEmail !== ''): ?>
                    <a href="mailto:<?= e($supportEmail) ?>"><?= Icon::svg('mail') ?><span><?= e($supportEmail) ?></span></a>
                <?php endif; ?>
                <?php if ($whatsapp !== ''): ?>
                    <a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= Icon::svg('phone') ?><span>+<?= e($whatsapp) ?></span></a>
                <?php endif; ?>
                <?php if ($phone !== ''): ?>
                    <a href="tel:<?= e((string) preg_replace('/[^\d+]/', '', $phone)) ?>"><?= Icon::svg('phone') ?><span><?= e($phone) ?></span></a>
                <?php endif; ?>
                <?php if ($platform): ?>
                    <a href="/status" class="sf-hide-sm"><?= Icon::svg('trend') ?><span>Network status</span></a>
                <?php endif; ?>
                <?php if ($supportEmail === '' && $whatsapp === '' && $phone === ''): ?>
                    <span><?= Icon::svg('headset') ?><span>Questions? <a href="/client/tickets/create">Contact our team</a></span></span>
                <?php endif; ?>
            </div>
            <div class="sf-topbar__tools">
                <?php if ($languages !== null && $t !== null && count($languages) > 1): ?>
                    <form method="post" action="/language" class="sf-topbar__form"><?= csrf_field() ?>
                        <input type="hidden" name="redirect" value="<?= e($redirectBack) ?>">
                        <select name="language_id" data-auto-submit aria-label="Select language">
                            <?php foreach ($languages as $language): ?>
                                <option value="<?= (int) $language['id'] ?>" <?= (int) $language['id'] === $t->languageId() ? 'selected' : '' ?>><?= e($language['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <?php if ($currencies !== null && $selectedCurrency !== null && count($currencies) > 1): ?>
                    <form method="post" action="/currency" class="sf-topbar__form"><?= csrf_field() ?>
                        <input type="hidden" name="redirect" value="<?= e($redirectBack) ?>">
                        <select name="currency_id" data-auto-submit aria-label="Select currency">
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?= (int) $currency['id'] ?>" <?= (int) $currency['id'] === (int) $selectedCurrency['id'] ? 'selected' : '' ?>><?= e($currency['code']) ?> (<?= e($currency['symbol']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <?php if ($client !== null): ?>
                    <a href="/client/dashboard"><?= Icon::svg('user') ?><span>My account</span></a>
                    <form method="post" action="/client/logout" class="sf-topbar__form"><?= csrf_field() ?>
                        <button type="submit" class="sf-topbar__link">Sign out</button>
                    </form>
                <?php else: ?>
                    <a href="/client/login"><?= Icon::svg('user') ?><span>Sign in</span></a>
                    <a href="/client/register" class="sf-hide-sm">Create account</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sf-navbar">
        <div class="sf-container sf-navbar__inner">
            <a href="/" class="sf-logo" aria-label="<?= e($brandName) ?> home">
                <?php if ($logo !== ''): ?>
                    <img src="<?= e($logo) ?>" alt="<?= e($brandName) ?>">
                <?php else: ?>
                    <span class="sf-logo__mark" aria-hidden="true"><?= Icon::svg('cloud') ?></span>
                    <span class="sf-logo__text"><?= e($brandName) ?></span>
                <?php endif; ?>
            </a>

            <nav class="sf-nav" aria-label="Main">
                <a class="sf-nav__link<?= $isActive('/') ? ' is-active' : '' ?>" href="/">Home</a>

                <div class="sf-nav__item" data-sf-dropdown>
                    <button type="button" class="sf-nav__link<?= $isActive('/store') ? ' is-active' : '' ?>" aria-expanded="false" aria-haspopup="true" data-sf-dropdown-toggle>
                        Services <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?>
                    </button>
                    <div class="sf-mega<?= count($categories) > 8 ? ' sf-mega--wide' : '' ?>" role="menu">
                        <div class="sf-mega__grid">
                            <?php foreach ($categories as $category): ?>
                                <a class="sf-mega__item" role="menuitem" href="/store?group_id=<?= (int) $category['id'] ?>">
                                    <span class="sf-chip"><?= Icon::svg(Icon::forCategory((string) $category['name'])) ?></span>
                                    <span class="sf-mega__body">
                                        <span class="sf-mega__title"><?= e((string) $category['name']) ?></span>
                                        <span class="sf-mega__meta">
                                            <?php if ($category['starting_price'] !== null): ?>
                                                From <?= e($money((float) $category['starting_price'])) ?><?= e($cycleShort($category['starting_cycle'] ?? null)) ?> ·
                                            <?php endif; ?>
                                            <?= (int) $category['product_count'] ?> <?= (int) $category['product_count'] === 1 ? 'plan' : 'plans' ?>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($categories === []): ?>
                                <span class="sf-mega__empty">New services are on the way.</span>
                            <?php endif; ?>
                        </div>
                        <a class="sf-mega__footer" href="/store">Browse all services <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                    </div>
                </div>

                <div class="sf-nav__item" data-sf-dropdown>
                    <button type="button" class="sf-nav__link<?= $isActive('/domains') ? ' is-active' : '' ?>" aria-expanded="false" aria-haspopup="true" data-sf-dropdown-toggle>
                        Domains <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?>
                    </button>
                    <div class="sf-dropdown" role="menu">
                        <a role="menuitem" href="/domains/register"><?= Icon::svg('search') ?><span><strong>Register a domain</strong><small>Find and claim your name</small></span></a>
                        <a role="menuitem" href="/domains/transfer"><?= Icon::svg('transfer') ?><span><strong>Transfer a domain</strong><small>Bring a domain you own</small></span></a>
                    </div>
                </div>

                <a class="sf-nav__link<?= $isActive('/deals') ? ' is-active' : '' ?>" href="/deals">Deals</a>

                <div class="sf-nav__item" data-sf-dropdown>
                    <button type="button" class="sf-nav__link<?= $isActive('/client/tickets') ? ' is-active' : '' ?>" aria-expanded="false" aria-haspopup="true" data-sf-dropdown-toggle>
                        Support <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?>
                    </button>
                    <div class="sf-dropdown" role="menu">
                        <a role="menuitem" href="/client/tickets/create"><?= Icon::svg('ticket') ?><span><strong>Open a ticket</strong><small>Our team replies by email</small></span></a>
                        <a role="menuitem" href="/client/tickets"><?= Icon::svg('headset') ?><span><strong>My tickets</strong><small>Follow up on a request</small></span></a>
                        <?php if ($whatsapp !== ''): ?>
                            <a role="menuitem" href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= Icon::svg('phone') ?><span><strong>Chat on WhatsApp</strong><small>+<?= e($whatsapp) ?></small></span></a>
                        <?php endif; ?>
                        <?php if ($platform): ?>
                            <a role="menuitem" href="/kb"><?= Icon::svg('code') ?><span><strong>Knowledgebase</strong><small>Guides and answers</small></span></a>
                            <a role="menuitem" href="/status"><?= Icon::svg('trend') ?><span><strong>Network status</strong><small>Live service health</small></span></a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($freeResellerNav): ?>
                    <a class="sf-nav__link sf-nav__link--promo<?= $isActive('/free-reseller') ? ' is-active' : '' ?>" href="/free-reseller">Free Reseller <span class="sf-nav__pill">Free</span></a>
                <?php endif; ?>
            </nav>

            <div class="sf-navbar__actions">
                <button type="button" class="sf-iconbtn" data-theme-toggle aria-label="Toggle dark mode">
                    <svg class="cv-icon-moon sf-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 8.7A5.5 5.5 0 0 1 7.3 2.5a5.5 5.5 0 1 0 6.2 6.2z"/></svg>
                    <svg class="cv-icon-sun sf-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M2.5 8H1M15 8h-1.5M3.5 3.5l1 1M11.5 11.5l1 1M12.5 3.5l-1 1M4.5 11.5l-1 1"/></svg>
                </button>
                <a href="/cart" class="sf-iconbtn" aria-label="Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?>">
                    <?= Icon::svg('cart') ?>
                    <?php if ($cartCount > 0): ?><span class="sf-badge"><?= $cartCount > 99 ? '99+' : $cartCount ?></span><?php endif; ?>
                </a>
                <?php if ($client !== null): ?>
                    <a href="/client/notifications" class="sf-iconbtn sf-hide-sm" aria-label="Notifications<?= $unreadNotifications > 0 ? ' (' . $unreadNotifications . ' unread)' : '' ?>">
                        <svg class="sf-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 10.5V7a5 5 0 0 0-10 0v3.5L1.5 12.5h13L13 10.5z"/><path d="M6.3 14a1.8 1.8 0 0 0 3.4 0"/></svg>
                        <?php if ($unreadNotifications > 0): ?><span class="sf-badge"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php endif; ?>
                    </a>
                    <div class="sf-nav__item sf-account sf-hide-md" data-sf-dropdown>
                        <button type="button" class="sf-account__toggle" aria-expanded="false" aria-haspopup="true" data-sf-dropdown-toggle>
                            <span class="sf-account__avatar" aria-hidden="true"><?= e($clientInitial) ?></span>
                            <span class="sf-account__label">Client area</span>
                            <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?>
                        </button>
                        <div class="sf-dropdown sf-dropdown--end" role="menu">
                            <div class="sf-account__head">
                                <strong><?= e($clientName) ?></strong>
                                <?php if (!empty($client['email'])): ?><small><?= e((string) $client['email']) ?></small><?php endif; ?>
                            </div>
                            <?php foreach ($accountLinks as [$href, $icon, $label]): ?>
                                <a role="menuitem" href="<?= e($href) ?>"><?= Icon::svg($icon) ?><span><strong><?= e($label) ?></strong></span></a>
                            <?php endforeach; ?>
                            <form method="post" action="/client/logout" class="sf-account__logout"><?= csrf_field() ?>
                                <button type="submit" role="menuitem"><?= Icon::svg('arrow') ?><span>Sign out</span></button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= $home ? '#plans' : '/store' ?>" class="sf-btn sf-btn--primary sf-hide-md">Get started</a>
                <?php endif; ?>
                <button type="button" class="sf-iconbtn sf-burger" aria-label="Open menu" aria-expanded="false" aria-controls="sf-drawer" data-sf-drawer-toggle>
                    <?= Icon::svg('menu') ?>
                </button>
            </div>
        </div>
    </div>

    <div class="sf-drawer" id="sf-drawer" data-sf-drawer hidden>
        <nav class="sf-drawer__nav" aria-label="Mobile">
            <a href="/">Home</a>
            <details<?= count($categories) > 8 ? '' : ' open' ?>>
                <summary>Services <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?></summary>
                <?php foreach ($categories as $category): ?>
                    <a class="sf-drawer__sub" href="/store?group_id=<?= (int) $category['id'] ?>">
                        <?= Icon::svg(Icon::forCategory((string) $category['name'])) ?><?= e((string) $category['name']) ?>
                    </a>
                <?php endforeach; ?>
                <a class="sf-drawer__sub" href="/store"><?= Icon::svg('arrow') ?>Browse all services</a>
            </details>
            <details>
                <summary>Domains <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?></summary>
                <a class="sf-drawer__sub" href="/domains/register"><?= Icon::svg('search') ?>Register a domain</a>
                <a class="sf-drawer__sub" href="/domains/transfer"><?= Icon::svg('transfer') ?>Transfer a domain</a>
            </details>
            <a href="/deals">Deals</a>
            <?php if ($freeResellerNav): ?>
                <a href="/free-reseller">Free Reseller <span class="sf-nav__pill">Free</span></a>
            <?php endif; ?>
            <a href="/client/tickets/create">Support</a>
            <?php if ($platform): ?>
                <a class="sf-drawer__sub" href="/kb"><?= Icon::svg('code') ?>Knowledgebase</a>
                <a class="sf-drawer__sub" href="/status"><?= Icon::svg('trend') ?>Network status</a>
            <?php endif; ?>
            <?php if ($supportEmail !== ''): ?>
                <a class="sf-drawer__sub" href="mailto:<?= e($supportEmail) ?>"><?= Icon::svg('mail') ?><?= e($supportEmail) ?></a>
            <?php endif; ?>
            <?php if ($whatsapp !== ''): ?>
                <a class="sf-drawer__sub" href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= Icon::svg('phone') ?>+<?= e($whatsapp) ?></a>
            <?php endif; ?>
            <?php if ($phone !== ''): ?>
                <a class="sf-drawer__sub" href="tel:<?= e((string) preg_replace('/[^\d+]/', '', $phone)) ?>"><?= Icon::svg('phone') ?><?= e($phone) ?></a>
            <?php endif; ?>
            <?php if ($client !== null): ?>
                <details>
                    <summary>My account <?= Icon::svg('chevron', 'sf-icon sf-icon--xs') ?></summary>
                    <?php foreach ($accountLinks as [$href, $icon, $label]): ?>
                        <a class="sf-drawer__sub" href="<?= e($href) ?>"><?= Icon::svg($icon) ?><?= e($label) ?></a>
                    <?php endforeach; ?>
                </details>
                <a href="/client/dashboard" class="sf-btn sf-btn--primary sf-btn--block">Client area</a>
                <form method="post" action="/client/logout" class="sf-drawer__logout"><?= csrf_field() ?>
                    <button type="submit" class="sf-btn sf-btn--ghost sf-btn--block">Sign out</button>
                </form>
            <?php else: ?>
                <a href="/client/login" class="sf-btn sf-btn--ghost sf-btn--block">Sign in</a>
                <a href="/client/register" class="sf-btn sf-btn--primary sf-btn--block">Create account</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
