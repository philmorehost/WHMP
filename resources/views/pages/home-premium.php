<?php
/**
 * The MAIN website's home page in the premium design (CodeVault\Theme\PlatformHome).
 *
 * Built from the same design system as a reseller's storefront (storefront.css,
 * the sf-* components) plus a few platform-only sections styled in platform.css:
 * the services grid, the guarantees band and the Free Reseller promo. Only ever
 * rendered on the platform's own host — a store's "/" is StorefrontHome.
 *
 * Prices, plan counts and TLD counts come from the live catalogue. The headline,
 * tagline and trust line are the admin's own words (Admin → Theme).
 *
 * @var string $headline
 * @var string $tagline
 * @var string $trustLine
 * @var array<int, array<string, mixed>> $categories
 * @var array<int, array<string, mixed>> $featured    categories with 'plans'
 * @var array<int, array{tld: string, price: float}> $domainPrices
 * @var int $tldCount
 * @var array{email?: string, phone?: string, whatsapp?: string, address?: string} $contact
 * @var array<string, mixed>|null $currency
 * @var array<string, mixed>|null $client
 * @var callable(float): string $money
 */
use CodeVault\Reseller\StorefrontHome;
use CodeVault\Reseller\StorefrontIcons as Icon;
use CodeVault\Theme\PlatformSite;

$headline ??= PlatformSite::DEFAULT_HEADLINE;
$tagline ??= PlatformSite::DEFAULT_TAGLINE;
$trustLine ??= PlatformSite::DEFAULT_TRUST;
$categories ??= [];
$featured ??= [];
$domainPrices ??= [];
$tldCount ??= 0;
$contact ??= [];
$currency ??= null;
$client ??= null;
$money ??= static fn (float $amount): string => number_format($amount, 2);
$freeReseller ??= null;

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

// The lowest plan price on the site, for the hero's floating card.
$cheapest = null;
$cheapestCycle = null;
foreach ($categories as $category) {
    if ($category['starting_price'] !== null && ($cheapest === null || (float) $category['starting_price'] < $cheapest)) {
        $cheapest = (float) $category['starting_price'];
        $cheapestCycle = $category['starting_cycle'] ?? null;
    }
}
$firstDomain = $domainPrices[0] ?? null;
$featured = array_values(array_filter($featured, static fn (array $group): bool => ($group['plans'] ?? []) !== []));
$currencyCode = trim((string) ($currency['code'] ?? ''));
$whatsapp = (string) ($contact['whatsapp'] ?? '');
$supportEmail = trim((string) ($contact['email'] ?? ''));

// The Free Reseller programme's public advert (null when switched off, or for a
// visitor who already resells). Injectable for tests.
if ($freeReseller === null) {
    $freeReseller = \CodeVault\Reseller\FreeResellerProgramme::advertFor(\CodeVault\Reseller\FreeResellerProgramme::PLACEMENT_PUBLIC);
}
$freeReseller = is_array($freeReseller) && $freeReseller !== [] ? $freeReseller : null;
$planTotal = array_sum(array_map(static fn (array $c): int => (int) ($c['product_count'] ?? 0), $categories));
?>
<section class="sf-hero pf-hero" aria-labelledby="sf-hero-title">
    <div class="sf-hero__decor" aria-hidden="true">
        <span class="sf-hero__glow sf-hero__glow--a"></span>
        <span class="sf-hero__glow sf-hero__glow--b"></span>
        <span class="sf-hero__grid"></span>
    </div>
    <div class="sf-container sf-hero__inner">
        <div class="sf-hero__copy">
            <span class="sf-eyebrow sf-eyebrow--light"><?= Icon::svg('bolt', 'sf-icon sf-icon--xs') ?> Hosting · VPS · Dedicated · Domains · Email</span>
            <h1 id="sf-hero-title" class="sf-hero__title"><?= e($headline) ?></h1>
            <p class="sf-hero__lead"><?= e($tagline) ?></p>
            <div class="sf-hero__actions">
                <a href="<?= $featured !== [] ? '#plans' : '/store' ?>" class="sf-btn sf-btn--primary sf-btn--lg">View plans <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                <?php if ($client !== null): ?>
                    <a href="/client/dashboard" class="sf-btn sf-btn--outline-light sf-btn--lg">Go to client area</a>
                <?php else: ?>
                    <a href="#domain" class="sf-btn sf-btn--outline-light sf-btn--lg">Find a domain</a>
                <?php endif; ?>
            </div>
            <ul class="sf-hero__ticks">
                <li><?= Icon::svg('check') ?> 24/7 expert support</li>
                <li><?= Icon::svg('check') ?> Instant online ordering</li>
                <?php if ($currencyCode !== ''): ?>
                    <li><?= Icon::svg('check') ?> Prices in <?= e($currencyCode) ?></li>
                <?php else: ?>
                    <li><?= Icon::svg('check') ?> Secure checkout</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="sf-hero__art" aria-hidden="true">
            <svg class="sf-iso" viewBox="0 0 420 380" role="presentation" focusable="false">
                <path class="sf-iso__floor" d="M210 141 390 236 210 331 30 236z"/>
                <path class="sf-iso__floor-ring" d="M210 171 333 236 210 301 87 236z"/>
                <?php
                // Three stacked isometric server units, drawn bottom first so each
                // sits on the one below. t = the y of a unit's top-face apex.
                foreach ([150, 108, 66] as $level => $top):
                    $h = 34;
                ?>
                    <g class="sf-iso__unit sf-iso__unit--<?= (int) $level ?>">
                        <path class="sf-iso__left" d="M110 <?= $top + 52 ?> 210 <?= $top + 104 ?>v<?= $h ?>L110 <?= $top + 52 + $h ?>z"/>
                        <path class="sf-iso__right" d="M310 <?= $top + 52 ?> 210 <?= $top + 104 ?>v<?= $h ?>L310 <?= $top + 52 + $h ?>z"/>
                        <path class="sf-iso__top" d="M210 <?= $top ?> 310 <?= $top + 52 ?> 210 <?= $top + 104 ?> 110 <?= $top + 52 ?>z"/>
                        <path class="sf-iso__led" d="M125 <?= $top + 72 ?>l10 5"/>
                        <path class="sf-iso__led sf-iso__led--b" d="M142 <?= $top + 81 ?>l10 5"/>
                        <path class="sf-iso__slot" d="M228 <?= $top + 107 ?> 292 <?= $top + 73 ?>"/>
                        <path class="sf-iso__slot" d="M228 <?= $top + 117 ?> 292 <?= $top + 83 ?>"/>
                    </g>
                <?php endforeach; ?>
                <g class="sf-iso__orbit">
                    <circle cx="62" cy="96" r="6"/>
                    <circle cx="364" cy="74" r="4"/>
                    <circle cx="382" cy="300" r="7"/>
                    <circle cx="40" cy="320" r="3"/>
                </g>
            </svg>

            <?php if ($cheapest !== null): ?>
                <div class="sf-float sf-float--a">
                    <span class="sf-chip sf-chip--sm"><?= Icon::svg('server') ?></span>
                    <span><small>Plans from</small><strong><?= e($money($cheapest)) ?><?= e($cycleShort($cheapestCycle)) ?></strong></span>
                </div>
            <?php endif; ?>
            <?php if ($firstDomain !== null): ?>
                <div class="sf-float sf-float--b">
                    <span class="sf-chip sf-chip--sm"><?= Icon::svg('globe') ?></span>
                    <span><small><?= e($firstDomain['tld']) ?> domains</small><strong><?= e($money((float) $firstDomain['price'])) ?>/yr</strong></span>
                </div>
            <?php endif; ?>
            <div class="sf-float sf-float--c">
                <span class="sf-chip sf-chip--sm"><?= Icon::svg('headset') ?></span>
                <span><small>Support</small><strong>24/7 expert team</strong></span>
            </div>
        </div>
    </div>
</section>

<section class="sf-domain" id="domain" aria-labelledby="sf-domain-title">
    <div class="sf-container">
        <div class="sf-domain__card">
            <div class="sf-domain__head">
                <h2 id="sf-domain-title">Find your perfect domain name</h2>
                <p>Search for a name and see straight away whether it is available<?= $tldCount > 0 ? ' — across ' . (int) $tldCount . ' extensions' : '' ?>.</p>
            </div>
            <form class="sf-domain__form" method="get" action="/domains/register" role="search">
                <label class="sf-visually-hidden" for="sf-domain-input">Domain name</label>
                <span class="sf-domain__icon"><?= Icon::svg('search') ?></span>
                <input id="sf-domain-input" type="text" name="domain" placeholder="yourbusiness.com" autocomplete="off" spellcheck="false" required>
                <button type="submit" class="sf-btn sf-btn--primary">Search</button>
            </form>
            <?php if ($domainPrices !== []): ?>
                <ul class="sf-domain__tlds">
                    <?php foreach ($domainPrices as $row): ?>
                        <li><strong><?= e($row['tld']) ?></strong><span><?= e($money((float) $row['price'])) ?>/yr</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="sf-domain__transfer" href="/domains/transfer"><?= Icon::svg('transfer', 'sf-icon sf-icon--xs') ?> Already own a domain? Transfer it to us</a>
        </div>
    </div>
</section>

<?php if ($categories !== []): ?>
    <section class="sf-section" id="services" aria-labelledby="pf-services-title">
        <div class="sf-container">
            <div class="sf-heading">
                <span class="sf-eyebrow">Our services</span>
                <h2 id="pf-services-title">Hosting solutions for every project</h2>
                <p>From a first website to busy online stores and dedicated hardware — <?= (int) $planTotal ?> <?= $planTotal === 1 ? 'plan' : 'plans' ?> across <?= count($categories) ?> <?= count($categories) === 1 ? 'category' : 'categories' ?>, all managed from one client area.</p>
            </div>
            <div class="pf-services">
                <?php foreach ($categories as $category): ?>
                    <a class="pf-service" href="/store?group_id=<?= (int) $category['id'] ?>">
                        <span class="sf-chip sf-chip--lg"><?= Icon::svg(Icon::forCategory((string) $category['name'])) ?></span>
                        <span class="pf-service__body">
                            <strong class="pf-service__name"><?= e((string) $category['name']) ?></strong>
                            <?php $categoryText = trim(strip_tags((string) ($category['description'] ?? ''))); ?>
                            <?php if ($categoryText !== ''): ?>
                                <span class="pf-service__text"><?= e(mb_strimwidth($categoryText, 0, 90, '…')) ?></span>
                            <?php endif; ?>
                            <span class="pf-service__meta">
                                <?php if ($category['starting_price'] !== null): ?>
                                    <span class="pf-service__price">From <b><?= e($money((float) $category['starting_price'])) ?></b><?= e($cycleShort($category['starting_cycle'] ?? null)) ?></span>
                                <?php endif; ?>
                                <span class="pf-service__count"><?= (int) $category['product_count'] ?> <?= (int) $category['product_count'] === 1 ? 'plan' : 'plans' ?></span>
                            </span>
                        </span>
                        <span class="pf-service__arrow" aria-hidden="true"><?= Icon::svg('arrow') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($featured !== []): ?>
    <section class="sf-section sf-section--tint" id="plans" aria-labelledby="sf-plans-title">
        <div class="sf-container">
            <div class="sf-heading">
                <span class="sf-eyebrow">Pricing</span>
                <h2 id="sf-plans-title">Popular plans, ready in minutes</h2>
                <p>A few of our most popular plans in each category. Compare every option in the <a href="/store">full catalogue</a>.</p>
            </div>

            <?php if (count($featured) > 1): ?>
                <div class="sf-tabs" role="tablist" aria-label="Plan categories" data-sf-tabs>
                    <?php foreach ($featured as $i => $group): ?>
                        <button type="button" role="tab" class="sf-tab<?= $i === 0 ? ' is-active' : '' ?>"
                                id="sf-tab-<?= (int) $group['id'] ?>" aria-controls="sf-panel-<?= (int) $group['id'] ?>"
                                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-sf-tab="sf-panel-<?= (int) $group['id'] ?>">
                            <?= e((string) $group['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($featured as $i => $group): ?>
                <?php $plans = (array) $group['plans']; ?>
                <div class="sf-plans" role="tabpanel" id="sf-panel-<?= (int) $group['id'] ?>"
                     aria-labelledby="sf-tab-<?= (int) $group['id'] ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                    <div class="sf-plans__grid sf-plans__grid--<?= min(3, count($plans)) ?>">
                        <?php foreach ($plans as $p => $plan): ?>
                            <?php
                            $features = StorefrontHome::features((string) ($plan['description'] ?? ''));
                            $highlight = count($plans) >= 3 ? $p === 1 : $p === 0;
                            ?>
                            <article class="sf-plan<?= $highlight ? ' sf-plan--highlight' : '' ?>">
                                <div class="sf-plan__head">
                                    <span class="sf-chip"><?= Icon::svg(Icon::forCategory((string) $group['name'])) ?></span>
                                    <div>
                                        <h3><?= e((string) $plan['name']) ?></h3>
                                        <span><?= e((string) $group['name']) ?></span>
                                    </div>
                                </div>
                                <div class="sf-plan__price">
                                    <span class="sf-plan__from">Starting at</span>
                                    <strong><?= e($money((float) $plan['starting_price'])) ?></strong><em><?= e($cycleShort($plan['starting_cycle'] ?? null)) ?></em>
                                </div>
                                <?php if ($features !== []): ?>
                                    <ul class="sf-plan__features">
                                        <?php foreach ($features as $feature): ?>
                                            <li><?= Icon::svg('check') ?><span><?= e($feature) ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif (trim(strip_tags((string) ($plan['description'] ?? ''))) !== ''): ?>
                                    <p class="sf-plan__text"><?= e(mb_strimwidth(trim(strip_tags((string) $plan['description'])), 0, 180, '…')) ?></p>
                                <?php endif; ?>
                                <a href="/store/<?= (int) $plan['id'] ?>" class="sf-btn <?= $highlight ? 'sf-btn--primary' : 'sf-btn--soft' ?> sf-btn--block">Order now</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="sf-plans__more">
                        <a href="/store?group_id=<?= (int) $group['id'] ?>">Compare all <?= (int) $group['product_count'] ?> <?= e((string) $group['name']) ?> plans <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="pf-guarantees" aria-labelledby="pf-guarantees-title">
    <div class="sf-container">
        <div class="sf-heading sf-heading--light">
            <span class="sf-eyebrow sf-eyebrow--light"><?= Icon::svg('shield', 'sf-icon sf-icon--xs') ?> Our guarantees</span>
            <h2 id="pf-guarantees-title">Why customers choose us</h2>
            <p><?= e($trustLine) ?></p>
        </div>
        <div class="pf-guarantees__grid">
            <?php foreach (PlatformSite::guarantees() as [$icon, $title, $text]): ?>
                <article class="pf-guarantee">
                    <span class="pf-guarantee__icon"><?= Icon::svg($icon) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($freeReseller !== null): ?>
    <section class="sf-section" aria-labelledby="pf-reseller-title">
        <div class="sf-container">
            <div class="pf-reseller">
                <div class="pf-reseller__copy">
                    <span class="sf-eyebrow sf-eyebrow--light"><?= Icon::svg('rocket', 'sf-icon sf-icon--xs') ?> Free Reseller programme</span>
                    <h2 id="pf-reseller-title"><?= e((string) ($freeReseller['headline'] ?? 'Start your own hosting business — free')) ?></h2>
                    <p><?= e((string) ($freeReseller['tagline'] ?? '')) ?></p>
                    <ul class="pf-reseller__ticks">
                        <li><?= Icon::svg('check') ?> Your own branded website</li>
                        <li><?= Icon::svg('check') ?> Sell VPS, dedicated, domains &amp; hosting</li>
                        <li><?= Icon::svg('check') ?> Set your prices, keep your profit</li>
                        <li><?= Icon::svg('check') ?> We run the servers and support</li>
                    </ul>
                    <div class="pf-reseller__actions">
                        <a href="/free-reseller" class="sf-btn sf-btn--light sf-btn--lg">Get Free Reseller <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                        <a href="/free-reseller#earn" class="sf-btn sf-btn--outline-light sf-btn--lg">See what you can earn</a>
                    </div>
                </div>
                <div class="pf-reseller__art" aria-hidden="true">
                    <div class="pf-reseller__card">
                        <span class="sf-chip"><?= Icon::svg('wallet') ?></span>
                        <span><small>Your price</small><strong>You decide</strong></span>
                    </div>
                    <div class="pf-reseller__card pf-reseller__card--b">
                        <span class="sf-chip"><?= Icon::svg('trend') ?></span>
                        <span><small>Your margin</small><strong>Paid to you</strong></span>
                    </div>
                    <div class="pf-reseller__card pf-reseller__card--c">
                        <span class="sf-chip"><?= Icon::svg('globe') ?></span>
                        <span><small>Your brand</small><strong>yourbrand.com</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="sf-section sf-section--tint" aria-labelledby="sf-about-title">
    <div class="sf-container sf-about">
        <div class="sf-about__art" aria-hidden="true">
            <div class="sf-mock">
                <div class="sf-mock__bar"><span></span><span></span><span></span></div>
                <div class="sf-mock__body">
                    <div class="sf-mock__side">
                        <i></i><i></i><i></i><i></i>
                    </div>
                    <div class="sf-mock__main">
                        <div class="sf-mock__tiles"><b></b><b></b><b></b></div>
                        <div class="sf-mock__chart">
                            <?php foreach ([40, 62, 48, 75, 58, 88, 70] as $h): ?>
                                <em style="height:<?= (int) $h ?>%"></em>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sf-about__badge">
                <span class="sf-chip"><?= Icon::svg('rocket') ?></span>
                <span><strong>Live in minutes</strong><small>Order, pay, launch</small></span>
            </div>
        </div>
        <div class="sf-about__copy">
            <span class="sf-eyebrow">All in one place</span>
            <h2 id="sf-about-title">Everything your business needs online, from one trusted provider</h2>
            <p>Websites, servers, domains and email that work together — ordered in a few clicks and managed from a
                single client area, with invoices, renewals, notifications and support all in one place.</p>
            <ul class="sf-checklist">
                <li><?= Icon::svg('check') ?> One account for every service</li>
                <li><?= Icon::svg('check') ?> Clear prices, no surprises</li>
                <li><?= Icon::svg('check') ?> Renewal reminders by email</li>
                <li><?= Icon::svg('check') ?> Help from a real team, 24/7</li>
            </ul>
            <div class="sf-stats">
                <div class="sf-stat">
                    <strong><?= (int) $planTotal ?></strong>
                    <span><?= $planTotal === 1 ? 'Plan to choose from' : 'Plans to choose from' ?></span>
                </div>
                <?php if ($tldCount > 0): ?>
                    <div class="sf-stat">
                        <strong><?= (int) $tldCount ?></strong>
                        <span>Domain extensions</span>
                    </div>
                <?php endif; ?>
                <div class="sf-stat">
                    <strong>24/7</strong>
                    <span>Expert support</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="sf-section" aria-labelledby="sf-steps-title">
    <div class="sf-container">
        <div class="sf-heading">
            <span class="sf-eyebrow">How it works</span>
            <h2 id="sf-steps-title">Get online fast, in three easy steps</h2>
        </div>
        <ol class="sf-steps">
            <li class="sf-step">
                <span class="sf-step__num">01</span>
                <span class="sf-chip sf-chip--lg"><?= Icon::svg('box') ?></span>
                <h3>Choose a plan</h3>
                <p>Pick shared hosting, a VPS, a dedicated server or email — whatever fits your project and budget.</p>
            </li>
            <li class="sf-step">
                <span class="sf-step__num">02</span>
                <span class="sf-chip sf-chip--lg"><?= Icon::svg('globe') ?></span>
                <h3>Add your domain</h3>
                <p>Register a new name or use one you already own — it only takes a moment.</p>
            </li>
            <li class="sf-step">
                <span class="sf-step__num">03</span>
                <span class="sf-chip sf-chip--lg"><?= Icon::svg('rocket') ?></span>
                <h3>Launch your website</h3>
                <p>Pay securely and manage everything from your own client area.</p>
            </li>
        </ol>
    </div>
</section>

<section class="sf-section sf-section--tint" aria-labelledby="sf-faq-title">
    <div class="sf-container sf-faq">
        <div class="sf-faq__intro">
            <span class="sf-eyebrow">FAQ</span>
            <h2 id="sf-faq-title">Frequently asked questions</h2>
            <p>Can't find what you are looking for? Our team is happy to help, day or night.</p>
            <div class="pf-faq__actions">
                <?php if ($whatsapp !== ''): ?>
                    <a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener" class="sf-btn sf-btn--primary"><?= Icon::svg('phone', 'sf-icon sf-icon--xs') ?> Chat on WhatsApp</a>
                    <a href="/client/tickets/create" class="sf-btn sf-btn--soft">Open a ticket</a>
                <?php else: ?>
                    <a href="/client/tickets/create" class="sf-btn sf-btn--primary">Ask a question <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                <?php endif; ?>
            </div>
            <?php if ($supportEmail !== ''): ?>
                <p class="pf-faq__email"><?= Icon::svg('mail', 'sf-icon sf-icon--xs') ?> <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></p>
            <?php endif; ?>
        </div>
        <div class="sf-faq__list">
            <?php
            $faq = [
                ['How do I place an order?', 'Choose a plan, pick your billing cycle and add a domain if you need one. Create an account at checkout, pay securely, and your service appears in your client area — most services are set up automatically.'],
                ['Can I use a domain I already own?', 'Yes. You can transfer your domain to us, or simply point it at your new hosting while keeping it where it is.'],
                ['Do you offer VPS and dedicated servers?', 'Yes. Alongside shared and reseller hosting we offer VPS and dedicated servers, and you can manage many of them — restart, reinstall and more — straight from your client area.'],
                ['Can I upgrade my plan later?', 'Yes. You can move to a bigger plan from your client area whenever your website needs more resources.'],
                ['How do I get help?', 'Open a support ticket from your client area' . ($whatsapp !== '' ? ' or chat with us on WhatsApp' : '') . ' — our team is available 24/7, and you can follow every request from the same place.'],
            ];

            if ($freeReseller !== null) {
                $faq[] = ['Can I resell your services under my own brand?', 'Yes — through our Free Reseller programme you get your own branded website, set your own prices and keep the margin, while we run the servers.'];
            }
            ?>
            <?php foreach ($faq as $i => [$question, $answer]): ?>
                <details class="sf-faq__item"<?= $i === 0 ? ' open' : '' ?>>
                    <summary><?= e($question) ?><span class="sf-faq__toggle" aria-hidden="true"></span></summary>
                    <p><?= e($answer) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
