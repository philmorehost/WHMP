<?php
/** @var string $state */
/** @var array<string, mixed>|null $credential */
/** @var array<string, mixed>|null $issued */
/** @var string|null $error */
/** @var string|null $notice */
/** @var array{service: float, domain: float} $discounts */
/** @var array<int, array<string, mixed>> $services */
/** @var array<int, array<string, mixed>> $domains */
/** @var array<string, mixed> $currency */
/** @var string $docsUrl */
/** @var int|null $userId */
/** @var int|null $resellerId */
/** @var array<string, mixed>|null $client */
/** @var array<string, mixed>|null $store the client's own store, once opened */
/** @var array<string, mixed>|null $upline the store this client registered under — a sub-reseller buys at ITS prices */
/** @var array{summary: ?array<string, int>, account: ?array<string, mixed>, pending_orders: ?int}|null $overview */

$servicePct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service']);
$domainPct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain']);
$client = $client ?? null;
$store = $store ?? null;
$upline = $upline ?? null;
$overview = $overview ?? ['summary' => null, 'account' => null, 'pending_orders' => null];
$summary = $overview['summary'] ?? null;
$account = $overview['account'] ?? null;
$uplineName = $upline === null ? '' : (trim((string) ($upline['brand_name'] ?? '')) !== '' ? (string) $upline['brand_name'] : (string) $upline['slug']);
$firstName = trim((string) ($client['first_name'] ?? ''));
$storeName = $store === null ? '' : (trim((string) ($store['brand_name'] ?? '')) !== '' ? (string) $store['brand_name'] : (string) $store['slug']);

// Inline stroke icons (24px grid, currentColor): no icon font, no extra request, and they
// take the tenant's brand colour from wherever they sit.
$icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
$i = [
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'server' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
    'wallet' => '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
    'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
    'percent' => '<path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M17 6l3 3M15 8l2 2"/>',
    'store' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>',
    'tag' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'layers' => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
];
$money = static fn (float $amount, string $code): string => number_format($amount, 2) . ($code === '' ? '' : ' ' . $code);

// The discounts were a sentence with two bolded percentages in it, which is the one thing
// on this page a reseller actually quotes to their own customers. As figures they are
// scannable, and the grid reflows instead of wrapping awkwardly mid-sentence.
$apiState = match ($state) {
    'active' => ['Active', 'Your key works now.'],
    'none' => ['Not requested', 'Request one below to start.'],
    default => ['Disabled', 'Submit your reselling domain below.'],
};
?>
<div class="rs-page">
    <section class="rs-welcome" aria-labelledby="rs-welcome-title">
        <div class="rs-welcome__body">
            <p class="rs-eyebrow">Reseller control panel</p>
            <header class="rs-head">
                <h1 class="rs-head__title" id="rs-welcome-title">
                    <?= $firstName !== '' ? 'Welcome back, ' . e($firstName) : 'Reseller area' ?>
                </h1>
                <p class="rs-head__lede">
                    <?php if ($upline !== null): ?>
                        Sell hosting and domain names under your own brand. You buy at
                        <strong><?= e($uplineName) ?></strong>'s prices and keep everything you add on top.
                    <?php else: ?>
                        Sell our services and domain names as your own. Your discounts are already applied to the
                        reseller prices shown on every page here, so the figures you see are the figures you pay.
                    <?php endif; ?>
                </p>
            </header>
            <p class="rs-admin-head__ids rs-welcome__ids">
                <?php if (($resellerId ?? null) !== null): ?><span class="rs-id-badge" title="Your store's unique ID">Reseller ID <?= (int) $resellerId ?></span><?php endif; ?>
                <?php if (($userId ?? null) !== null): ?><span class="rs-id-badge rs-id-badge--user" title="Your account's user ID">User ID <?= (int) $userId ?></span><?php endif; ?>
                <?php if ($upline !== null): ?>
                    <span class="rs-chip" title="You registered with this provider">Sub-reseller of <?= e($uplineName) ?></span>
                <?php else: ?>
                    <span class="rs-chip">Partner reseller</span>
                <?php endif; ?>
            </p>
            <div class="rs-welcome__actions">
                <?php if ($store === null): ?>
                    <a class="cv-btn" href="/client/reseller/store"><?= $icon($i['store']) ?> Open your store</a>
                <?php else: ?>
                    <a class="cv-btn" href="/client/reseller/clients"><?= $icon($i['users']) ?> Manage customers</a>
                    <a class="cv-btn cv-btn--secondary" href="/client/reseller/store"><?= $icon($i['store']) ?> <?= e($storeName) ?></a>
                <?php endif; ?>
                <a class="cv-btn cv-btn--secondary" href="/client/dashboard">&larr; Back to dashboard</a>
            </div>
        </div>
        <div class="rs-welcome__art" aria-hidden="true">
            <span class="rs-welcome__orb rs-welcome__orb--a"></span>
            <span class="rs-welcome__orb rs-welcome__orb--b"></span>
            <span class="rs-welcome__glyph"><?= $icon($i['layers']) ?></span>
        </div>
    </section>

    <?= $view->render('partials.reseller-nav') ?>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <div class="rs-stats rs-stats--kpi">
        <?php if ($summary !== null): ?>
            <a class="rs-stat rs-stat--link" href="/client/reseller/clients">
                <span class="rs-stat__icon"><?= $icon($i['users']) ?></span>
                <div class="rs-stat__label">Customers</div>
                <div class="rs-stat__value"><?= (int) $summary['customers'] ?></div>
                <div class="rs-stat__note">registered on your store</div>
            </a>
            <div class="rs-stat rs-stat--teal">
                <span class="rs-stat__icon"><?= $icon($i['server']) ?></span>
                <div class="rs-stat__label">Active services</div>
                <div class="rs-stat__value"><?= (int) $summary['services_active'] ?></div>
                <div class="rs-stat__note"><?= (int) $summary['domains'] ?> domain<?= (int) $summary['domains'] === 1 ? '' : 's' ?> &middot; <?= (int) $summary['services_suspended'] ?> suspended</div>
            </div>
        <?php endif; ?>
        <?php if ($account !== null): ?>
            <a class="rs-stat rs-stat--link rs-stat--violet" href="/client/reseller/account">
                <span class="rs-stat__icon"><?= $icon($i['wallet']) ?></span>
                <div class="rs-stat__label">Earnings balance</div>
                <div class="rs-stat__value"><?= e($money((float) ($account['balance'] ?? 0), (string) ($account['currency_code'] ?? ''))) ?></div>
                <div class="rs-stat__note"><?= e($money((float) ($account['withdrawable'] ?? 0), (string) ($account['currency_code'] ?? ''))) ?> ready to withdraw</div>
            </a>
        <?php endif; ?>
        <?php if (($overview['pending_orders'] ?? null) !== null): ?>
            <a class="rs-stat rs-stat--link rs-stat--amber" href="/client/reseller/clients">
                <span class="rs-stat__icon"><?= $icon($i['cart']) ?></span>
                <div class="rs-stat__label">Pending orders</div>
                <div class="rs-stat__value"><?= (int) $overview['pending_orders'] ?></div>
                <div class="rs-stat__note"><?= $summary !== null ? (int) $summary['invoices_unpaid'] . ' unpaid invoice' . ((int) $summary['invoices_unpaid'] === 1 ? '' : 's') : 'awaiting payment' ?></div>
            </a>
        <?php endif; ?>
        <?php if ($upline !== null): ?>
            <div class="rs-stat">
                <span class="rs-stat__icon"><?= $icon($i['tag']) ?></span>
                <div class="rs-stat__label">Your buying price</div>
                <div class="rs-stat__value rs-stat__value--sm"><?= e($uplineName) ?>'s prices</div>
                <div class="rs-stat__note">your markup is your margin</div>
            </div>
        <?php else: ?>
            <div class="rs-stat">
                <span class="rs-stat__icon"><?= $icon($i['percent']) ?></span>
                <div class="rs-stat__label">Service discount</div>
                <div class="rs-stat__value"><?= e($servicePct) ?>%</div>
                <div class="rs-stat__note">off our list prices</div>
            </div>
            <div class="rs-stat rs-stat--teal">
                <span class="rs-stat__icon"><?= $icon($i['globe']) ?></span>
                <div class="rs-stat__label">Domain discount</div>
                <div class="rs-stat__value"><?= e($domainPct) ?>%</div>
                <div class="rs-stat__note">off our list prices</div>
            </div>
        <?php endif; ?>
        <div class="rs-stat rs-stat--violet">
            <span class="rs-stat__icon"><?= $icon($i['key']) ?></span>
            <div class="rs-stat__label">API key</div>
            <div class="rs-stat__value rs-stat__value--sm"><?= e($apiState[0]) ?></div>
            <div class="rs-stat__note"><?= e($apiState[1]) ?></div>
        </div>
    </div>

    <?php if ($upline !== null): ?>
        <div class="cv-alert cv-alert--neutral rs-callout">
            <strong>How your pricing works.</strong> You registered with <?= e($uplineName) ?>, so you buy every
            service and domain at <?= e($uplineName) ?>'s prices (shown below) and set your own prices on top under
            <a href="/client/reseller/prices">Your prices</a>. Your store can have as many customers as you like;
            they cannot open reseller accounts of their own.
        </div>
    <?php endif; ?>

    <div class="rs-tiles">
        <a class="rs-tile" href="/client/reseller/clients">
            <span class="rs-tile__icon"><?= $icon($i['users']) ?></span>
            <span class="rs-tile__text"><strong>Customers</strong><span>Add, sign in as and manage your customers</span></span>
            <span class="rs-tile__go"><?= $icon($i['arrow']) ?></span>
        </a>
        <a class="rs-tile" href="/client/reseller/prices">
            <span class="rs-tile__icon"><?= $icon($i['tag']) ?></span>
            <span class="rs-tile__text"><strong>Your prices</strong><span>Set a markup or price each item</span></span>
            <span class="rs-tile__go"><?= $icon($i['arrow']) ?></span>
        </a>
        <a class="rs-tile" href="/client/reseller/store">
            <span class="rs-tile__icon"><?= $icon($i['store']) ?></span>
            <span class="rs-tile__text"><strong>Store &amp; branding</strong><span>Logo, colours, domain and live chat</span></span>
            <span class="rs-tile__go"><?= $icon($i['arrow']) ?></span>
        </a>
        <a class="rs-tile" href="/client/reseller/tickets">
            <span class="rs-tile__icon"><?= $icon($i['chat']) ?></span>
            <span class="rs-tile__text"><strong>Support tickets</strong><span>Answer your customers under your brand</span></span>
            <span class="rs-tile__go"><?= $icon($i['arrow']) ?></span>
        </a>
    </div>

<?php if (is_array($issued)): ?>
    <div class="cv-card rs-card--success">
        <h2 class="cv-card__title"><?= $issued['rotated'] ? 'Your new API credentials' : 'Your API credentials' ?></h2>
        <p><strong>Copy the secret now.</strong> It is shown once and stored only as a hash — we cannot show it
            again. If you lose it, rotate the key to issue a new one.</p>
        <p><strong>API key:</strong><br><code><?= e((string) $issued['key']) ?></code></p>
        <p><strong>API secret:</strong><br><code><?= e((string) $issued['secret']) ?></code></p>
        <p>Send both on every request as:</p>
        <p><code>Authorization: Bearer <?= e((string) $issued['key']) ?>.<?= e((string) $issued['secret']) ?></code></p>
        <p style="color:var(--cv-text-secondary);"><?= $state === 'active'
            ? 'This key is active.'
            : 'This key is <strong>not active yet</strong> — submit your reselling domain below.' ?></p>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h2 class="cv-card__title">Your API access</h2>

    <?php if ($state === 'none'): ?>
        <p>You do not have a reseller API key yet. Request one to get started — it is created
            <strong>disabled</strong>, and switches on once you tell us the domain you will resell from.</p>
        <form method="post" action="/client/reseller/key"><?= csrf_field() ?>
            <p>
                <label for="label">Label (optional)</label><br>
                <input class="cv-input" type="text" id="label" name="label" maxlength="120"
                       placeholder="My storefront" value="">
            </p>
            <button class="cv-btn" type="submit">Request API key</button>
        </form>
    <?php else: ?>
        <table class="cv-table">
            <tbody>
            <tr>
                <th>API key</th>
                <td><code><?= e((string) ($credential['api_key'] ?? '')) ?></code></td>
            </tr>
            <tr>
                <th>Status</th>
                <td>
                    <?php if ($state === 'active'): ?>
                        <span class="cv-badge cv-badge--success">Active</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Disabled — awaiting your domain</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Reselling domain</th>
                <td><?= ($credential['reseller_domain'] ?? null) !== null
                    ? e((string) $credential['reseller_domain'])
                    : '<em>not submitted yet</em>' ?></td>
            </tr>
            <tr>
                <th>Scopes</th>
                <td>
                    <?php
                    // The column is JSON; decoding here matches the API-credentials
                    // admin view so the two render the same value identically.
                    $scopes = json_decode((string) ($credential['scopes'] ?? ''), true);
                    $scopes = is_array($scopes) ? $scopes : [];
                    ?>
                    <?php if (in_array('*', $scopes, true)): ?>
                        <span class="cv-badge cv-badge--neutral">All scopes</span>
                    <?php else: ?>
                        <code><?= e(implode(', ', $scopes)) ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (($credential['activated_at'] ?? null) !== null): ?>
                <tr>
                    <th>Activated</th>
                    <td><?= e((string) $credential['activated_at']) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <h3><?= $state === 'active' ? 'Change the reselling domain' : 'Activate your key' ?></h3>
        <p><?= $state === 'active'
            ? 'Submit a different domain to move this key to another storefront.'
            : 'Your key stays disabled until you submit the domain name you will resell our services and domains from.' ?></p>
        <form method="post" action="/client/reseller/activate"><?= csrf_field() ?>
            <p>
                <label for="domain">Domain name</label><br>
                <input class="cv-input" type="text" id="domain" name="domain" maxlength="253"
                       placeholder="reseller.example.com" required
                       value="<?= e((string) ($credential['reseller_domain'] ?? '')) ?>">
            </p>
            <button class="cv-btn" type="submit"><?= $state === 'active' ? 'Update domain' : 'Activate API key' ?></button>
        </form>

        <h3>Lost the secret?</h3>
        <p>Rotating issues a new key and secret immediately. <strong>The old secret stops working at once</strong>,
            and the new key starts out disabled until you re-submit your domain.</p>
        <form method="post" action="/client/reseller/rotate"
              onsubmit="return confirm('Rotate the key? Your existing secret will stop working immediately.');">
            <?= csrf_field() ?>
            <input type="hidden" name="label" value="<?= e((string) ($credential['label'] ?? 'Reseller key')) ?>">
            <button class="cv-btn" type="submit">Rotate key &amp; secret</button>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Service reseller prices</h2>
    <?php if ($upline !== null): ?>
        <p style="color:var(--cv-text-secondary);">What you pay <?= e($uplineName) ?>, in <?= e((string) $currency['code']) ?>. Setup fees are at their prices too.</p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);">Prices in <?= e((string) $currency['code']) ?>. Setup fees are discounted at the same rate.</p>
    <?php endif; ?>
    <div class="rs-table-scroll">
    <table class="cv-table">
        <thead>
        <?php if ($upline !== null): ?>
            <tr><th>Service</th><th>Billing cycle</th><th>You pay</th></tr>
        <?php else: ?>
            <tr><th>Service</th><th>Billing cycle</th><th>List price</th><th>Your discount</th><th>You pay</th></tr>
        <?php endif; ?>
        </thead>
        <tbody>
        <?php foreach ($services as $product): ?>
            <?php foreach ($product['cycles'] as $cycle): ?>
                <tr>
                    <td><?= e((string) $product['name']) ?></td>
                    <td><?= e((string) $cycle['label']) ?></td>
                    <?php if ($upline === null): ?>
                        <td><?= e((string) $cycle['price']['list']) ?></td>
                        <td><span class="cv-badge cv-badge--success"><?= e((string) $cycle['price']['discount_label']) ?></span> save <?= e((string) $cycle['price']['saving']) ?></td>
                    <?php endif; ?>
                    <td><strong><?= e((string) $cycle['price']['reseller']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($services === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No services are available to resell yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Domain reseller prices</h2>
    <p style="color:var(--cv-text-secondary);">Prices in <?= e((string) $currency['code']) ?>.</p>
    <div class="rs-table-scroll">
    <table class="cv-table">
        <thead>
        <tr><th>TLD</th><th>Register</th><th>Transfer</th><th>Renew</th><?php if ($upline === null): ?><th>Discount</th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($domains as $row): ?>
            <tr>
                <td><strong><?= e((string) $row['tld']) ?></strong></td>
                <td><strong><?= e((string) $row['register']['reseller']) ?></strong>
                    <?php if ($upline === null): ?><br><span style="color:var(--cv-text-secondary);">was <?= e((string) $row['register']['list']) ?></span><?php endif; ?></td>
                <td><strong><?= e((string) $row['transfer']['reseller']) ?></strong></td>
                <td><strong><?= e((string) $row['renew']['reseller']) ?></strong></td>
                <?php if ($upline === null): ?><td><span class="cv-badge cv-badge--success"><?= e((string) $row['register']['discount_label']) ?></span></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($domains === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No TLD pricing is configured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p style="color:var(--cv-text-secondary);">These are quote prices — the API reports what you pay, not what your
        customer is billed. You invoice your own customers.</p>
</div>
</div>
