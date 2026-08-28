<?php
/** @var CodeVault\View $view */
/** @var string $title */
/** @var array<int, array<string, mixed>>|null $currencies */
/** @var array<string, mixed>|null $selectedCurrency */
/** @var CodeVault\Localization\Translation|null $t */
/** @var array<int, array<string, mixed>>|null $languages */
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string}|null $theme */
$currencies ??= null;
$selectedCurrency ??= null;
$t ??= null;
$languages ??= null;
$theme ??= ['brandName' => brand_name(), 'logoUrl' => null];
?>
<?php
$container = \CodeVault\Support\App::container();
$db = $container->make(\CodeVault\Database::class);
$categories = [];
try {
    $categories = $db->select("SELECT id, name FROM product_groups ORDER BY id ASC");
} catch (\Throwable $e) {}

// Unread notification bell — resolved here rather than threaded through
// every controller that renders this layout, same reasoning as $categories
// above. Only meaningful for a logged-in client; a guest browsing the
// storefront has no notifications to show.
$notificationsClient = null;
$unreadNotifications = 0;
try {
    $notificationsClient = $container->make(\CodeVault\Clients\ClientAuthGuard::class)->currentClient();
    if ($notificationsClient !== null) {
        $unreadNotifications = $container->make(\CodeVault\Notifications\ClientNotificationRepository::class)->unreadCount((int) $notificationsClient['id']);
    }
} catch (\Throwable $e) {}

// Currency switcher — resolved here rather than threaded through every
// controller that renders this layout, same reasoning as $categories
// above. Store/cart pages still pass their own $currencies /
// $selectedCurrency (that wins); everywhere else the effective display
// currency (session choice > client preference > default) is resolved so
// the switcher is consistent across every client page — including the
// client dashboard, which previously rendered without one.
if ($currencies === null || $selectedCurrency === null) {
    try {
        $currencyRepo = $container->make(\CodeVault\Billing\CurrencyRepository::class);
        $currencyService = $container->make(\CodeVault\Billing\CurrencyService::class);
        $currencySelection = $container->make(\CodeVault\Billing\CurrencySelection::class);
        $currencies = $currencyRepo->all();
        $selectedCurrency = $currencyService->resolveEffective($notificationsClient, $currencySelection->get());
    } catch (\Throwable) {
        $currencies = [];
        $selectedCurrency = null;
    }
}
?>
<header class="cv-topbar" style="position:relative;display:flex;align-items:center;justify-content:space-between;padding:var(--cv-space-4) var(--cv-space-6);border-bottom:1px solid var(--cv-border-default);">
    <a href="/client/dashboard" style="text-decoration:none; color:inherit; display:flex;align-items:center;gap:var(--cv-space-2);flex-shrink:0;font-weight:bold;">
        <?php if (!empty($theme['logoUrl'])): ?>
            <?php
            // A logo hosted on this install (under /assets or /uploads) goes
            // through the WebP pipeline (img()) so browsers get an optimized
            // derivative with far-future cache headers; an external CDN URL is
            // served as-is.
            $logo = (string) $theme['logoUrl'];
            if (str_starts_with($logo, '/assets') || str_starts_with($logo, '/uploads')) {
                $logo = img($logo, 320);
            }
            ?>
            <img src="<?= e($logo) ?>" alt="<?= e($theme['brandName']) ?>" style="height:1.75rem;">
        <?php else: ?>
            <?php // The masthead is the brand, not the page title — this used to
                  // print $title, so the login page (title "CodeVault") showed the
                  // product name where the company name belongs. ?>
            <?= e($theme['brandName'] ?? brand_name()) ?>
        <?php endif; ?>
    </a>

    <nav style="display:flex; gap:var(--cv-space-4); margin-left:var(--cv-space-6); font-size:var(--cv-text-sm); font-weight:600; align-items:center; flex:1;" class="cv-nav-links">
        <a href="/client/dashboard" style="color:var(--cv-text-primary); text-decoration:none;">Dashboard</a>

        <div style="position:relative; display:inline-block; cursor:pointer; padding-bottom:var(--cv-space-2); margin-bottom:calc(-1 * var(--cv-space-2));" class="cv-dropdown">
            <span style="color:var(--cv-text-primary); display:flex; align-items:center; gap:4px; font-weight:600;">
                Store <span style="font-size:0.7em;">▼</span>
            </span>
            <div class="cv-dropdown-menu" style="display:none; position:absolute; top:100%; left:0; background:var(--cv-bg-surface); border:1px solid var(--cv-border-default); border-radius:var(--cv-radius-md); box-shadow:var(--cv-shadow-md); min-width:200px; z-index:9999;">
                <a href="/store" style="display:block; padding:var(--cv-space-2) var(--cv-space-3); color:var(--cv-text-primary); text-decoration:none; border-bottom:1px solid var(--cv-border-default); font-weight:700;">Browse All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="/store?group_id=<?= (int) $cat['id'] ?>" style="display:block; padding:var(--cv-space-2) var(--cv-space-3); color:var(--cv-text-primary); text-decoration:none; font-weight:500;"><?= e($cat['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="position:relative; display:inline-block; cursor:pointer; padding-bottom:var(--cv-space-2); margin-bottom:calc(-1 * var(--cv-space-2));" class="cv-dropdown">
            <span style="color:var(--cv-text-primary); display:flex; align-items:center; gap:4px; font-weight:600;">
                Domains <span style="font-size:0.7em;">▼</span>
            </span>
            <div class="cv-dropdown-menu" style="display:none; position:absolute; top:100%; left:0; background:var(--cv-bg-surface); border:1px solid var(--cv-border-default); border-radius:var(--cv-radius-md); box-shadow:var(--cv-shadow-md); min-width:200px; z-index:9999;">
                <a href="/domains/register" style="display:block; padding:var(--cv-space-2) var(--cv-space-3); color:var(--cv-text-primary); text-decoration:none; border-bottom:1px solid var(--cv-border-default); font-weight:500;">Register New Domain</a>
                <a href="/domains/transfer" style="display:block; padding:var(--cv-space-2) var(--cv-space-3); color:var(--cv-text-primary); text-decoration:none; font-weight:500;">Transfer a Domain</a>
            </div>
        </div>

        <a href="/client/invoices" style="color:var(--cv-text-primary); text-decoration:none;">Billing</a>
        <a href="/client/tickets" style="color:var(--cv-text-primary); text-decoration:none;">Support</a>
    </nav>

    <button type="button" class="cv-mobile-menu-toggle" data-mobile-menu-toggle aria-label="Toggle menu" aria-expanded="false">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 5.5h14M3 10h14M3 14.5h14"/></svg>
    </button>

    <nav class="cv-mobile-nav" data-mobile-nav>
        <a href="/client/dashboard">Dashboard</a>
        <a href="/store">Store</a>
        <?php foreach ($categories as $cat): ?>
            <a href="/store?group_id=<?= (int) $cat['id'] ?>" class="cv-mobile-nav__sub">&nbsp;&nbsp;&nbsp;<?= e($cat['name']) ?></a>
        <?php endforeach; ?>
        <a href="/domains/register">Register New Domain</a>
        <a href="/domains/transfer">Transfer a Domain</a>
        <a href="/client/invoices">Billing</a>
        <a href="/client/emails">My Emails</a>
        <a href="/client/payment-methods">Payment Methods</a>
        <a href="/client/tickets">Support</a>
    </nav>

    <style>
    /* No gap between the "Store" trigger and its dropdown — the trigger's
       padding-bottom + the menu's negative-offsetting margin-bottom keep
       the hoverable area continuous, so moving the mouse straight down
       from the trigger to the menu never crosses a non-hovered gap that
       would close it (the old margin-top:8px on the menu created exactly
       that dead zone). */
    .cv-dropdown:hover .cv-dropdown-menu {
        display: block !important;
    }
    .cv-dropdown-menu a:hover {
        background: var(--cv-bg-surface-sunken) !important;
    }
    .cv-mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        padding: var(--cv-space-2);
        color: var(--cv-text-primary);
        cursor: pointer;
        width: 2rem;
        height: 2rem;
        flex-shrink: 0;
    }
    .cv-mobile-nav {
        display: none;
        flex-direction: column;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--cv-bg-surface);
        border-bottom: 1px solid var(--cv-border-default);
        box-shadow: var(--cv-shadow-md);
        z-index: 9998;
    }
    .cv-mobile-nav.cv-mobile-nav--open {
        display: flex;
    }
    .cv-mobile-nav a {
        padding: var(--cv-space-3) var(--cv-space-6);
        color: var(--cv-text-primary);
        text-decoration: none;
        font-weight: 600;
        border-top: 1px solid var(--cv-border-subtle);
    }
    .cv-mobile-nav a.cv-mobile-nav__sub {
        font-weight: 500;
        color: var(--cv-text-secondary);
        font-size: var(--cv-text-sm);
    }
    @media (max-width: 768px) {
        .cv-nav-links {
            display: none !important;
        }
        .cv-mobile-menu-toggle {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }
    }
    </style>

    <div class="cv-topbar__actions" style="display:flex;gap:var(--cv-space-3);align-items:center;flex-shrink:0;flex-wrap:wrap;max-width:100%;">
        <?php if ($notificationsClient !== null): ?>
            <a href="/client/notifications" aria-label="Notifications" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;color:var(--cv-text-primary);flex-shrink:0;">
                <svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10.5V7a5 5 0 0 0-10 0v3.5L1.5 12.5h13L13 10.5z"/><path d="M6.3 14a1.8 1.8 0 0 0 3.4 0"/></svg>
                <?php if ($unreadNotifications > 0): ?>
                    <span style="position:absolute;top:-2px;right:-2px;background:#ef4444;color:#fff;border-radius:999px;font-size:.62rem;font-weight:800;padding:1px 4px;line-height:1.4;min-width:1.1rem;text-align:center;"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <?php if ($languages !== null && $t !== null && count($languages) > 1): ?>
            <form method="post" action="/language" style="margin:0;max-width:100%;"><?= csrf_field() ?>
                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? '/store') ?>">
                <select class="cv-input" name="language_id" data-auto-submit aria-label="Select language" style="width:auto;max-width:100%;">
                    <?php foreach ($languages as $language): ?>
                        <option value="<?= (int) $language['id'] ?>" <?= (int) $language['id'] === $t->languageId() ? 'selected' : '' ?>>
                            <?= e($language['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($currencies !== null && $selectedCurrency !== null && count($currencies) > 1): ?>
            <form method="post" action="/currency" style="margin:0;max-width:100%;"><?= csrf_field() ?>
                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? '/store') ?>">
                <select class="cv-input" name="currency_id" data-auto-submit aria-label="Select display currency" style="width:auto;max-width:100%;">
                    <?php foreach ($currencies as $currency): ?>
                        <option value="<?= (int) $currency['id'] ?>" <?= (int) $currency['id'] === (int) $selectedCurrency['id'] ? 'selected' : '' ?>>
                            <?= e($currency['code']) ?> (<?= e($currency['symbol']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <button type="button" class="cv-theme-toggle" data-theme-toggle aria-label="Toggle dark mode" style="flex-shrink:0;">
            <svg class="cv-icon-moon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 8.7A5.5 5.5 0 0 1 7.3 2.5a5.5 5.5 0 1 0 6.2 6.2z"/></svg>
            <svg class="cv-icon-sun" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M2.5 8H1M15 8h-1.5M3.5 3.5l1 1M11.5 11.5l1 1M12.5 3.5l-1 1M4.5 11.5l-1 1"/></svg>
        </button>
    </div>
</header>
