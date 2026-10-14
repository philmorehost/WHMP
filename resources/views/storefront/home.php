<?php
/**
 * A reseller storefront's home page (StorefrontHome).
 *
 * Laid out after a premium hosting template: hero with an illustrated server
 * stack, a domain search bar overlapping the hero, a features grid, an "about"
 * split, a few featured plans in category tabs (one tab visible at a time),
 * three steps to get online, and an FAQ. The categories and the full catalogue
 * are deliberately NOT listed here — they live under SERVICES in the menu — so
 * the page stays short however many categories the store sells.
 *
 * Honest by construction: every figure on this page (prices, plan counts, TLD
 * counts) comes from the store's own catalogue at its own prices. No review
 * scores, years in business or customer counts — a white-label page cannot
 * know them. No colour is written here; storefront.css styles everything from
 * the store's brand tokens.
 *
 * @var string $headline
 * @var string $tagline
 * @var array<int, array<string, mixed>> $categories
 * @var array<int, array<string, mixed>> $featured    categories with 'plans'
 * @var array<int, array{tld: string, price: float}> $domainPrices
 * @var int $tldCount
 * @var array<string, mixed>|null $currency
 * @var callable(float): string $money
 */
use CodeVault\Reseller\StorefrontHome;
use CodeVault\Reseller\StorefrontIcons as Icon;

$headline ??= StorefrontHome::DEFAULT_HEADLINE;
$tagline ??= StorefrontHome::DEFAULT_TAGLINE;
$categories ??= [];
$featured ??= [];
$domainPrices ??= [];
$tldCount ??= 0;
$currency ??= null;
$money ??= static fn (float $amount): string => number_format($amount, 2);

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
?>
<section class="sf-hero" aria-labelledby="sf-hero-title">
    <div class="sf-hero__decor" aria-hidden="true">
        <span class="sf-hero__glow sf-hero__glow--a"></span>
        <span class="sf-hero__glow sf-hero__glow--b"></span>
        <span class="sf-hero__grid"></span>
    </div>
    <div class="sf-container sf-hero__inner">
        <div class="sf-hero__copy">
            <span class="sf-eyebrow sf-eyebrow--light"><?= Icon::svg('bolt', 'sf-icon sf-icon--xs') ?> Hosting · Domains · Email</span>
            <h1 id="sf-hero-title" class="sf-hero__title"><?= e($headline) ?></h1>
            <p class="sf-hero__lead"><?= e($tagline) ?></p>
            <div class="sf-hero__actions">
                <a href="<?= $featured !== [] ? '#plans' : '/store' ?>" class="sf-btn sf-btn--primary sf-btn--lg">Get started <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                <a href="#domain" class="sf-btn sf-btn--outline-light sf-btn--lg">Find a domain</a>
            </div>
            <ul class="sf-hero__ticks">
                <li><?= Icon::svg('check') ?> Order online in minutes</li>
                <li><?= Icon::svg('check') ?> Secure checkout</li>
                <?php if ($currencyCode !== ''): ?>
                    <li><?= Icon::svg('check') ?> Prices in <?= e($currencyCode) ?></li>
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
                <span class="sf-chip sf-chip--sm"><?= Icon::svg('lock') ?></span>
                <span><small>Checkout</small><strong>Secure &amp; encrypted</strong></span>
            </div>
        </div>
    </div>
</section>

<section class="sf-domain" id="domain" aria-labelledby="sf-domain-title">
    <div class="sf-container">
        <div class="sf-domain__card">
            <div class="sf-domain__head">
                <h2 id="sf-domain-title">Find your perfect domain name</h2>
                <p>Search for a name and see straight away whether it is available.</p>
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

<section class="sf-section" aria-labelledby="sf-features-title">
    <div class="sf-container">
        <div class="sf-heading">
            <span class="sf-eyebrow">Why choose us</span>
            <h2 id="sf-features-title">Discover powerful hosting features</h2>
            <p>Everything you need to launch, run and grow a website — without the technical headaches.</p>
        </div>
        <div class="sf-features">
            <?php foreach ([
                ['shield', 'Secure by default', 'Encrypted checkout and protected accounts keep your details and your website safe.'],
                ['bolt', 'Fast performance', 'Plans built for speed, so your pages load quickly for every visitor.'],
                ['panel', 'Easy control panel', 'Manage files, email and settings from a simple, familiar control panel.'],
                ['globe', 'Domains made simple', 'Register a new domain or transfer one you own, right alongside your hosting.'],
                ['trend', 'Grow as you go', 'Start small and upgrade whenever your website needs more room.'],
                ['headset', 'Friendly support', 'Real people ready to help — open a ticket from your client area any time.'],
            ] as [$icon, $title, $text]): ?>
                <article class="sf-feature">
                    <span class="sf-chip sf-chip--lg"><?= Icon::svg($icon) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

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
            <h2 id="sf-about-title">Everything your website needs, from one trusted provider</h2>
            <p>Hosting, domains and email that work together — ordered in a few clicks and managed from a single
                client area, with invoices, renewals and support all in one place.</p>
            <ul class="sf-checklist">
                <li><?= Icon::svg('check') ?> One account for every service</li>
                <li><?= Icon::svg('check') ?> Clear prices, no surprises</li>
                <li><?= Icon::svg('check') ?> Renewal reminders by email</li>
                <li><?= Icon::svg('check') ?> Help from a real team</li>
            </ul>
            <div class="sf-stats">
                <div class="sf-stat">
                    <strong><?= count($categories) ?></strong>
                    <span><?= count($categories) === 1 ? 'Service category' : 'Service categories' ?></span>
                </div>
                <?php if ($tldCount > 0): ?>
                    <div class="sf-stat">
                        <strong><?= (int) $tldCount ?></strong>
                        <span>Domain extensions</span>
                    </div>
                <?php endif; ?>
                <div class="sf-stat">
                    <strong>24/7</strong>
                    <span>Online ordering</span>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($featured !== []): ?>
    <section class="sf-section" id="plans" aria-labelledby="sf-plans-title">
        <div class="sf-container">
            <div class="sf-heading">
                <span class="sf-eyebrow">Pricing</span>
                <h2 id="sf-plans-title">Choose the right plan for your website</h2>
                <p>A few of our most popular plans. See every option under <a href="/store">Services</a>.</p>
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
                <p>Pick the hosting, email or service that fits your project and budget.</p>
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
            <p>Can't find what you are looking for? Our team is happy to help.</p>
            <a href="/client/tickets/create" class="sf-btn sf-btn--primary">Ask a question <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
        </div>
        <div class="sf-faq__list">
            <?php foreach ([
                ['How do I place an order?', 'Choose a plan under Services, pick your billing cycle and add a domain if you need one. Create an account at checkout, pay securely, and your service appears in your client area.'],
                ['Can I use a domain I already own?', 'Yes. You can transfer your domain to us, or simply point it at your new hosting while keeping it where it is.'],
                ['Can I upgrade my plan later?', 'Yes. You can move to a bigger plan from your client area whenever your website needs more resources.'],
                ['How do I get help?', 'Open a support ticket from your client area and our team will reply by email. You can follow every request from the same place.'],
                ['Which currencies can I pay in?', 'Prices are shown in the currency selected at the top of the page, and you can switch it at any time before you check out.'],
            ] as $i => [$question, $answer]): ?>
                <details class="sf-faq__item"<?= $i === 0 ? ' open' : '' ?>>
                    <summary><?= e($question) ?><span class="sf-faq__toggle" aria-hidden="true"></span></summary>
                    <p><?= e($answer) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
