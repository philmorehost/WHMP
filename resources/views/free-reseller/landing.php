<?php
/**
 * The Free Reseller programme's landing page (main website only).
 *
 * Every rule quoted here — discount, holding period, payout minimum, cost billing
 * period, maximum markup, the free store address — is read live from the settings
 * that enforce it, so this page cannot promise what the platform no longer does.
 *
 * @var bool $enabled
 * @var bool $adminPreview
 * @var string $headline
 * @var string $tagline
 * @var array<int, array{label: string, url: string}> $demoLinks
 * @var array<string, mixed> $facts
 * @var array<int, array<string, mixed>> $categories
 * @var int $tldCount
 * @var array<int, array<string, mixed>> $domainPrices
 * @var array{list: float, cost: float, retail: float, profit: float} $example
 * @var string $currencySymbol
 * @var float $currencyRate
 * @var array<string, mixed>|null $client
 * @var bool $ownsStore
 * @var bool $canApply
 */
use CodeVault\Reseller\StorefrontIcons;

$adminPreview ??= false;
$icon = static fn (string $name): string => StorefrontIcons::svg($name, '');
$money = static fn (float $base): string => $currencySymbol . number_format(round($base * $currencyRate, 2), 2);
$pct = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.') . '%';

$holdingDays = (int) $facts['holdingDays'];
$holdingText = $holdingDays === 0 ? 'straight away' : 'after ' . $holdingDays . ' day' . ($holdingDays === 1 ? '' : 's');
$minimum = (string) $facts['payoutMinimum'] . ' ' . (string) $facts['payoutCurrency'];
$period = (string) $facts['billingPeriod'];
$serviceDiscount = (float) $facts['serviceDiscount'];
$domainDiscount = (float) $facts['domainDiscount'];
$storeDomain = $facts['storeDomain'] !== null ? (string) $facts['storeDomain'] : null;
$platformHost = (string) $facts['platformHost'];

$applyUrl = '/free-reseller/apply';
$primaryHref = $ownsStore ? '/client/reseller' : $applyUrl;
$primaryLabel = $ownsStore ? 'Open your Reseller Area' : 'Get my free reseller website';

// Emphasise the word "free" in the headline without trusting the admin's text as HTML.
$headlineHtml = e($headline);
$headlineHtml = (string) preg_replace('~\b(free)\b~i', '<em>$1</em>', $headlineHtml, 1);

$mockPlans = array_slice(array_values(array_filter($categories, static fn (array $c): bool => $c['formatted_price'] !== null)), 0, 3);
?>
<style>.cv-shell__main { padding: 0 !important; }</style>
<link rel="stylesheet" href="<?= asset('assets/css/free-reseller.css') ?>">

<div class="fr">

<?php if (!$enabled && !$adminPreview): ?>
    <div class="fr-wrap">
        <div class="fr-card fr-closed">
            <div class="fr-icon" style="margin:0 auto 14px;"><?= $icon('rocket') ?></div>
            <h1 style="font-size:1.6rem;margin-bottom:10px;">The Free Reseller programme is not open right now</h1>
            <p style="color:var(--cv-text-secondary);">We are not taking new reseller applications at the moment. Please check back soon, or contact our team if you would like to be told when it reopens.</p>
            <p style="margin-top:20px;"><a class="fr-btn fr-btn--outline" href="/">Back to the home page</a></p>
        </div>
    </div>
</div>
<?php return; endif; ?>

<?php if ($adminPreview): ?>
    <div style="background:#7c3aed;color:#fff;text-align:center;padding:10px 16px;font-weight:700;font-size:.9rem;">
        Admin preview — the programme is switched OFF, so visitors see a "not open" notice. Turn it on in
        <a href="/admin/resellers/free-programme" style="color:#fff;text-decoration:underline;">Resellers → Free programme</a>.
    </div>
<?php endif; ?>

<!-- ============================== HERO ============================== -->
<section class="fr-hero">
    <div class="fr-wrap fr-hero__grid">
        <div>
            <span class="fr-eyebrow"><span class="fr-eyebrow__dot"></span> Free Reseller Programme</span>
            <h1><?= $headlineHtml ?></h1>
            <p class="fr-hero__lead"><?= e($tagline) ?></p>
            <div class="fr-hero__ctas">
                <a class="fr-btn fr-btn--primary fr-btn--lg" href="<?= e($primaryHref) ?>"><?= e($primaryLabel) ?> <?= $icon('arrow') ?></a>
                <?php if ($demoLinks !== []): ?>
                    <a class="fr-btn fr-btn--ghost fr-btn--lg" href="#demos">See demo websites</a>
                <?php else: ?>
                    <a class="fr-btn fr-btn--ghost fr-btn--lg" href="#how">How it works</a>
                <?php endif; ?>
            </div>
            <ul class="fr-hero__ticks">
                <li><?= $icon('check') ?> No setup or monthly fee</li>
                <li><?= $icon('check') ?> Your brand, your prices</li>
                <li><?= $icon('check') ?> We run the servers &amp; support</li>
            </ul>
        </div>

        <div class="fr-mock" aria-hidden="true">
            <div class="fr-mock__bar"><i></i><i></i><i></i><span class="fr-mock__url">https://www.yourbrand.com</span></div>
            <div class="fr-mock__body">
                <div class="fr-mock__nav"><span>YourBrand</span><span>Services ▾ &nbsp; Domains &nbsp; Support</span></div>
                <div class="fr-mock__hero">
                    <strong>Fast, reliable hosting for your business</strong>
                    <div class="fr-mock__search"><span>Find your perfect domain…</span><b>Search</b></div>
                </div>
                <div class="fr-mock__plans">
                    <?php if ($mockPlans !== []): ?>
                        <?php foreach ($mockPlans as $plan): ?>
                            <div class="fr-mock__plan"><?= e((string) $plan['name']) ?><b><?= e($money((float) $plan['starting_price'] * 1.3)) ?></b></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="fr-mock__plan">Web Hosting<b>Your price</b></div>
                        <div class="fr-mock__plan">VPS<b>Your price</b></div>
                        <div class="fr-mock__plan">Dedicated<b>Your price</b></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fr-mock__badge"><?= $icon('trend') ?><div>You earn<b>+<?= e($money($example['profit'])) ?> / sale</b></div></div>
        </div>
    </div>
</section>

<!-- ============================== STATS ============================== -->
<div class="fr-stats">
    <div class="fr-wrap">
        <div class="fr-stats__grid">
            <div class="fr-stat"><div class="fr-stat__value">Free</div><div class="fr-stat__label">Your website, forever — no setup fee</div></div>
            <div class="fr-stat"><div class="fr-stat__value"><?= count($categories) > 0 ? count($categories) . '+' : 'All' ?></div><div class="fr-stat__label">Product ranges you can sell</div></div>
            <div class="fr-stat"><div class="fr-stat__value"><?= $tldCount > 0 ? (int) $tldCount : '∞' ?></div><div class="fr-stat__label">Domain extensions</div></div>
            <div class="fr-stat"><div class="fr-stat__value"><?= $serviceDiscount > 0 ? e($pct($serviceDiscount)) . ' off' : 'Up to ' . e($pct((float) $facts['maxMarkup'])) ?></div><div class="fr-stat__label"><?= $serviceDiscount > 0 ? 'Your wholesale discount' : 'Markup — you set it' ?></div></div>
        </div>
    </div>
</div>

<!-- ============================== HOW IT WORKS ============================== -->
<section class="fr-section" id="how">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">How it works</div>
            <h2>Your hosting business, live in four steps</h2>
            <p>No servers to buy, no software to install, no staff to hire. You bring a name and a domain — we give you everything else.</p>
        </div>
        <div class="fr-steps">
            <div class="fr-card fr-step"><span class="fr-step__num">1</span><span class="fr-icon"><?= $icon('rocket') ?></span><h3>Apply for free</h3><p>Choose your business name and domain. Register a new domain while you apply, or use one you already own.</p></div>
            <div class="fr-card fr-step"><span class="fr-step__num">2</span><span class="fr-icon fr-icon--violet"><?= $icon('panel') ?></span><h3>Brand your website</h3><p>Add your logo, colours, favicon, homepage text and chat. Your customers only ever see <strong>your</strong> brand.</p></div>
            <div class="fr-card fr-step"><span class="fr-step__num">3</span><span class="fr-icon fr-icon--gold"><?= $icon('tag') ?></span><h3>Set your prices</h3><p>Add one markup for everything, or price each product yourself. You decide how much you earn.</p></div>
            <div class="fr-card fr-step"><span class="fr-step__num">4</span><span class="fr-icon fr-icon--green"><?= $icon('wallet') ?></span><h3>Sell &amp; get paid</h3><p>Customers order and pay on your website. Your profit builds up in your balance and is paid out to your bank.</p></div>
        </div>
    </div>
</section>

<!-- ============================== WHAT YOU CAN SELL ============================== -->
<section class="fr-section fr-section--tint" id="sell">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">What you can sell</div>
            <h2>Our whole catalogue, under your name</h2>
            <p>VPS, dedicated servers, web hosting, domains and more — everything we sell, ready on your website from day one, set up automatically when your customer pays.</p>
        </div>
        <div class="fr-products">
            <?php if ($categories !== []): ?>
                <?php foreach ($categories as $category): ?>
                    <div class="fr-card fr-product">
                        <span class="fr-icon"><?= $icon((string) $category['icon']) ?></span>
                        <h3><?= e((string) $category['name']) ?></h3>
                        <div class="fr-product__meta"><?= (int) $category['product_count'] ?> plan<?= (int) $category['product_count'] === 1 ? '' : 's' ?> ready to sell</div>
                        <?php if ($category['formatted_price'] !== null): ?>
                            <div class="fr-product__price">Our price from <b><?= e((string) $category['formatted_price']) ?></b></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ([['cloud', 'VPS Servers'], ['server', 'Dedicated Servers'], ['code', 'Web Hosting'], ['mail', 'Business Email']] as [$i, $label]): ?>
                    <div class="fr-card fr-product"><span class="fr-icon"><?= $icon($i) ?></span><h3><?= e($label) ?></h3><div class="fr-product__meta">Ready to sell</div></div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="fr-card fr-product">
                <span class="fr-icon fr-icon--green"><?= $icon('globe') ?></span>
                <h3>Domain names</h3>
                <div class="fr-product__meta"><?= $tldCount > 0 ? (int) $tldCount . ' extensions' : 'Popular extensions' ?> — registration, transfer &amp; renewal</div>
                <?php if ($domainDiscount > 0): ?><div class="fr-product__price">You get <b><?= e($pct($domainDiscount)) ?> off</b></div><?php endif; ?>
            </div>
        </div>
        <?php if ($domainPrices !== []): ?>
            <div class="fr-tlds">
                <?php foreach ($domainPrices as $tld): ?>
                    <span class="fr-tld"><?= e((string) $tld['tld']) ?><span><?= e((string) $tld['formatted_price']) ?>/yr</span></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================== EARNINGS ============================== -->
<section class="fr-section" id="earn">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">How you earn</div>
            <h2>You set the price. The difference is yours.</h2>
            <p>
                <?php if ($serviceDiscount > 0): ?>
                    You buy at our price <strong>less <?= e($pct($serviceDiscount)) ?></strong> and sell at whatever price you choose.
                <?php else: ?>
                    You buy at our price and sell at whatever price you choose.
                <?php endif; ?>
                Every sale and every renewal earns you the difference — automatically, for as long as the customer stays.
            </p>
        </div>
        <div class="fr-earn">
            <div class="fr-card">
                <h3 style="font-size:1.15rem;margin-bottom:6px;">Example: one sale with a 30% markup</h3>
                <p class="fr-note" style="margin:0 0 6px !important;">Real figures from our catalogue.</p>
                <ul class="fr-breakdown">
                    <li><span>Our normal price</span><b><?= e($money($example['list'])) ?></b></li>
                    <li><span>What it costs you<?= $serviceDiscount > 0 ? ' (' . e($pct($serviceDiscount)) . ' reseller discount)' : '' ?></span><b><?= e($money($example['cost'])) ?></b></li>
                    <li><span>Your price to your customer (+30%)</span><b><?= e($money($example['retail'])) ?></b></li>
                    <li class="fr-breakdown__profit"><span><strong>Your profit on this sale</strong></span><b><?= e($money($example['profit'])) ?></b></li>
                </ul>
                <p class="fr-note">
                    Set one markup for your whole store (up to <?= e($pct((float) $facts['maxMarkup'])) ?>), then fine-tune any product with its own price.
                    Run your own promo codes and discount banners too.
                </p>
            </div>

            <div class="fr-card fr-calc" data-fr-calc
                 data-discount="<?= e((string) $serviceDiscount) ?>"
                 data-symbol="<?= e($currencySymbol) ?>"
                 data-max-markup="<?= e((string) $facts['maxMarkup']) ?>">
                <h3 style="font-size:1.15rem;">Earnings calculator</h3>
                <div>
                    <label for="fr-calc-price">Our price per month <output data-out="price"></output></label>
                    <input class="fr-range" type="range" id="fr-calc-price" data-in="price" min="1" max="<?= e((string) max(100, (int) ceil($example['list'] * $currencyRate * 10))) ?>" step="1" value="<?= e((string) max(1, (int) round($example['list'] * $currencyRate))) ?>">
                </div>
                <div>
                    <label for="fr-calc-markup">Your markup <output data-out="markup"></output></label>
                    <input class="fr-range" type="range" id="fr-calc-markup" data-in="markup" min="0" max="200" step="5" value="30">
                </div>
                <div>
                    <label for="fr-calc-customers">Paying customers <output data-out="customers"></output></label>
                    <input class="fr-range" type="range" id="fr-calc-customers" data-in="customers" min="1" max="500" step="1" value="25">
                </div>
                <div class="fr-calc__result" aria-live="polite">
                    <small>Your estimated profit</small>
                    <div class="fr-calc__big"><span data-out="monthly">—</span><span style="font-size:1rem;font-weight:600;opacity:.8;"> / month</span></div>
                    <div class="fr-calc__row"><span>Per customer</span><b data-out="each">—</b></div>
                    <div class="fr-calc__row"><span>Per year</span><b data-out="yearly">—</b></div>
                </div>
                <p class="fr-note" style="margin-top:0 !important;">An estimate to show how it adds up — your real earnings depend on what you sell and the prices you set.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============================== WHO GETS PAID & WHEN ============================== -->
<section class="fr-section fr-section--tint" id="payouts">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">Payments &amp; payouts</div>
            <h2>Who receives the money — and when you get paid</h2>
            <p>We handle the payment side for you, so you never chase a card payment or a bank transfer. Here is exactly how money moves.</p>
        </div>
        <div class="fr-flow">
            <div class="fr-flow__item"><div class="fr-flow__dot">1</div><h3>Your customer pays</h3><p>On your website, through our secure checkout. We receive the payment on your behalf.</p></div>
            <div class="fr-flow__item"><div class="fr-flow__dot">2</div><h3>Credited to you</h3><p>The full amount your customer paid is added to your reseller balance the moment the invoice is paid.</p></div>
            <div class="fr-flow__item"><div class="fr-flow__dot">3</div><h3>Cost settled</h3><p>Our wholesale cost for what you sold is taken from your balance once a <?= e($period) ?>. What is left is your profit.</p></div>
            <div class="fr-flow__item"><div class="fr-flow__dot">4</div><h3>Short hold</h3><p><?= $holdingDays === 0 ? 'Your profit is available to withdraw straight away.' : 'Each payment is held for ' . $holdingDays . ' days to cover refunds and chargebacks, then becomes yours to withdraw.' ?></p></div>
            <div class="fr-flow__item"><div class="fr-flow__dot"><?= $icon('wallet') ?></div><h3>You withdraw</h3><p>Request a payout from your Reseller Area once you have <?= e($minimum) ?> or more. We send it to your bank account.</p></div>
        </div>
        <div class="fr-facts">
            <div class="fr-card fr-fact"><span class="fr-icon"><?= $icon('shield') ?></span><div><b>We collect</b><span>Customer payments are received by us and credited to your balance</span></div></div>
            <div class="fr-card fr-fact"><span class="fr-icon fr-icon--gold"><?= $icon('lock') ?></span><div><b><?= $holdingDays === 0 ? 'No hold' : $holdingDays . '-day hold' ?></b><span>Funds become withdrawable <?= e($holdingText) ?></span></div></div>
            <div class="fr-card fr-fact"><span class="fr-icon fr-icon--green"><?= $icon('wallet') ?></span><div><b><?= e($minimum) ?></b><span>Minimum payout (or the same value in your currency)</span></div></div>
            <div class="fr-card fr-fact"><span class="fr-icon fr-icon--violet"><?= $icon('transfer') ?></span><div><b>Bank transfer</b><span>Paid to the bank account you save, with a payment reference</span></div></div>
        </div>
    </div>
</section>

<!-- ============================== WE HANDLE SUPPORT ============================== -->
<section class="fr-section" id="support">
    <div class="fr-wrap fr-split">
        <div>
            <div class="fr-head__kicker">Relax — we do the heavy lifting</div>
            <h2>You make the money. We handle the whole thing.</h2>
            <p class="fr-lead">Running a hosting company usually means servers, provisioning, billing systems and a support team. With us, that is all already done — you focus on finding customers.</p>
            <ul class="fr-checks">
                <li><?= $icon('check') ?><div><strong>Servers &amp; uptime</strong><span>We run, monitor and maintain every server and network around the clock.</span></div></li>
                <li><?= $icon('check') ?><div><strong>Automatic setup</strong><span>Hosting, VPS, dedicated servers and domains are set up automatically when your customer pays.</span></div></li>
                <li><?= $icon('check') ?><div><strong>Technical support</strong><span>Your customers open tickets on your website, under your brand. Pass any ticket to our team with one click and we solve it.</span></div></li>
                <li><?= $icon('check') ?><div><strong>Billing on autopilot</strong><span>Invoices, payment reminders, renewals and receipts go out automatically — from your own brand.</span></div></li>
            </ul>
        </div>
        <div class="fr-card fr-who">
            <h3 style="font-size:1.1rem;margin-bottom:4px;">Who does what</h3>
            <div class="fr-who__row"><span class="fr-icon fr-icon--gold"><?= $icon('user') ?></span><div><h3>Find &amp; talk to customers</h3><p>Marketing, your prices, your relationships</p></div><span class="fr-who__tag fr-who__tag--you">You</span></div>
            <div class="fr-who__row"><span class="fr-icon fr-icon--gold"><?= $icon('panel') ?></span><div><h3>Your brand &amp; website look</h3><p>Logo, colours, homepage text, chat</p></div><span class="fr-who__tag fr-who__tag--you">You</span></div>
            <div class="fr-who__row"><span class="fr-icon fr-icon--green"><?= $icon('server') ?></span><div><h3>Servers, network &amp; setup</h3><p>Hardware, uptime, automatic provisioning</p></div><span class="fr-who__tag fr-who__tag--us">Us</span></div>
            <div class="fr-who__row"><span class="fr-icon fr-icon--green"><?= $icon('headset') ?></span><div><h3>Technical support</h3><p>Escalated tickets solved by our team</p></div><span class="fr-who__tag fr-who__tag--us">Us</span></div>
            <div class="fr-who__row"><span class="fr-icon fr-icon--green"><?= $icon('shield') ?></span><div><h3>Payments &amp; billing system</h3><p>Checkout, invoices, renewals, payouts</p></div><span class="fr-who__tag fr-who__tag--us">Us</span></div>
        </div>
    </div>
</section>

<!-- ============================== DOMAIN ACTIVATION ============================== -->
<section class="fr-section fr-section--tint" id="domain">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">Activating your domain</div>
            <h2>The website is free — you just need a domain</h2>
            <p>Your store runs on your own domain name, so customers remember <strong>you</strong>. Pick whichever path suits you.</p>
        </div>
        <div class="fr-domain">
            <div class="fr-card fr-domain__path">
                <span class="fr-icon fr-icon--green"><?= $icon('search') ?></span>
                <h3>Option A — Register a new domain with us</h3>
                <p style="color:var(--cv-text-secondary);">The easiest way. Search for a name right on the application form.</p>
                <ol>
                    <li><strong>Search</strong> for the domain you want and pick one that is available.</li>
                    <li><strong>Pay</strong> for the domain at checkout — this is the only thing you pay for.</li>
                    <li>We <strong>register it</strong> and connect it to your store. Our team approves it and sets everything up.</li>
                    <li>A free <strong>SSL certificate</strong> (the padlock) is issued, and your website is live.</li>
                </ol>
            </div>
            <div class="fr-card fr-domain__path">
                <span class="fr-icon"><?= $icon('globe') ?></span>
                <h3>Option B — Use a domain you already own</h3>
                <p style="color:var(--cv-text-secondary);">Bought your domain somewhere else? No problem.</p>
                <ol>
                    <li><strong>Enter</strong> your domain on the application form.</li>
                    <li><strong>Prove it is yours</strong>: at your domain provider, add a TXT record named <span class="fr-code">_codevault-verify.yourdomain.com</span> with the code we give you — or point the domain at us with a CNAME to <span class="fr-code"><?= e($platformHost) ?></span>.</li>
                    <li>Press <strong>Verify</strong> in your Store settings. Our team then approves the domain and adds it to our servers.</li>
                    <li>A free <strong>SSL certificate</strong> is issued and your website goes live.</li>
                </ol>
            </div>
        </div>
        <div class="fr-callout">
            <span class="fr-icon"><?= $icon('bolt') ?></span>
            <div>
                <?php if ($storeDomain !== null): ?>
                    <strong>Start selling while your domain connects.</strong> Every store also gets a free address like
                    <span class="fr-code">yourname.<?= e($storeDomain) ?></span> that works straight away.
                <?php else: ?>
                    <strong>Step-by-step help is built in.</strong> After you apply, your Store settings page shows a live go-live checklist with the exact DNS records for your domain, and our team is on hand if you get stuck.
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============================== MASTER RESELLER ============================== -->
<section class="fr-section" id="master">
    <div class="fr-wrap fr-split">
        <div>
            <div class="fr-head__kicker">Master reseller</div>
            <h2>Build a team of resellers under you</h2>
            <p class="fr-lead">Your own customers can open reseller websites too — with you as their supplier. Every time one of them makes a sale, you earn as well.</p>
            <ul class="fr-checks">
                <li><?= $icon('check') ?><div><strong>They buy at your prices</strong><span>Your resellers pay your retail price as their cost, so your margin is protected on everything they sell.</span></div></li>
                <li><?= $icon('check') ?><div><strong>You earn on every sale they make</strong><span>The difference is credited to your balance automatically, on the same payout schedule.</span></div></li>
                <li><?= $icon('check') ?><div><strong>They get the full toolkit</strong><span>Their own branded website, domain, prices, customers and support desk — just like yours.</span></div></li>
                <li><?= $icon('check') ?><div><strong>Simple and clear</strong><span>Up to <?= (int) $facts['maxTier'] ?> levels: you, then your resellers. Their customers are customers only, so the chain never gets confusing.</span></div></li>
            </ul>
        </div>
        <div class="fr-tiers">
            <div class="fr-tier"><span class="fr-icon fr-icon--green"><?= $icon('server') ?></span><div><h3>Us — the platform</h3><p>Servers, provisioning, support, payments</p></div></div>
            <div class="fr-tier__arrow">▼ wholesale price<?= $serviceDiscount > 0 ? ' (−' . e($pct($serviceDiscount)) . ')' : '' ?></div>
            <div class="fr-tier fr-tier--you"><span class="fr-icon fr-icon--gold"><?= $icon('user') ?></span><div><h3>You — master reseller</h3><p>Earn on your customers <strong>and</strong> your resellers' sales</p></div></div>
            <div class="fr-tier__arrow">▼ your retail price</div>
            <div class="fr-tier"><span class="fr-icon fr-icon--violet"><?= $icon('panel') ?></span><div><h3>Your resellers</h3><p>Their own branded websites, their own markup</p></div></div>
            <div class="fr-tier__arrow">▼ their retail price</div>
            <div class="fr-tier"><span class="fr-icon"><?= $icon('cart') ?></span><div><h3>Their customers</h3><p>Buy hosting, VPS, servers and domains</p></div></div>
        </div>
    </div>
</section>

<!-- ============================== FEATURES ============================== -->
<section class="fr-section fr-section--tint" id="features">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">Everything included — free</div>
            <h2>A complete hosting business in a box</h2>
            <p>The same tools we use to run our own business, ready for yours.</p>
        </div>
        <div class="fr-features">
            <?php
            $features = [
                ['panel', 'White-label website', 'A modern store with a services menu, domain search and checkout — showing only your brand, never ours.'],
                ['globe', 'Your own domain + SSL', 'Run on your own domain with a free security certificate.'],
                ['tag', 'Your own prices', 'One store-wide markup, plus a custom price on any product or domain.'],
                ['user', 'Customer management', 'View and edit customers, reset passwords, sign in as them, suspend or unsuspend services and manage their domains.'],
                ['headset', 'Branded support desk', 'Answer tickets under your brand, or escalate any ticket to our team in one click.'],
                ['mail', 'Branded emails', 'Invoices, receipts and notices go out from your own email address and name.'],
                ['bolt', 'Promo codes & banners', 'Create your own discount codes and promotional banners for your store.'],
                ['phone', 'Live chat', 'Add WhatsApp or Tawk.to chat so customers can reach you instantly.'],
                ['server', 'Automatic provisioning', 'Hosting, VPS, dedicated servers and domains set up the moment a customer pays.'],
                ['trend', 'Earnings dashboard', 'See your balance, what is withdrawable, statements and every payout.'],
                ['transfer', 'Bring your customers', 'Move existing customers into your store with a guided account move.'],
                ['code', 'Reseller API', 'Connect your own systems with a documented API and your own key.'],
            ];
            ?>
            <?php foreach ($features as [$i, $title, $text]): ?>
                <div class="fr-card fr-feature"><span class="fr-icon"><?= $icon($i) ?></span><div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($demoLinks !== []): ?>
<!-- ============================== DEMOS ============================== -->
<section class="fr-section" id="demos">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">See it live</div>
            <h2>Demo reseller websites</h2>
            <p>This is what your website could look like. Open a demo, browse the services and try the domain search.</p>
        </div>
        <div class="fr-demos">
            <?php foreach ($demoLinks as $demo): ?>
                <a class="fr-card fr-demo" href="<?= e($demo['url']) ?>" target="_blank" rel="noopener noreferrer">
                    <div class="fr-demo__shot" aria-hidden="true"></div>
                    <div>
                        <h3><?= e($demo['label']) ?></h3>
                        <span><?= e((string) (parse_url($demo['url'], PHP_URL_HOST) ?: $demo['url'])) ?></span>
                    </div>
                    <span class="fr-demo__go">Open demo <?= $icon('arrow') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================== FAQ ============================== -->
<section class="fr-section<?= $demoLinks !== [] ? ' fr-section--tint' : '' ?>" id="faq">
    <div class="fr-wrap">
        <div class="fr-head">
            <div class="fr-head__kicker">Questions</div>
            <h2>Frequently asked questions</h2>
        </div>
        <div class="fr-faq">
            <details open><summary>Is the reseller website really free?</summary><p>Yes. There is no setup fee and no monthly fee for your website. The only thing you may pay for is your domain name. When your customers buy, our wholesale cost is taken from what they paid, and the rest is your profit.</p></details>
            <details><summary>Do I need technical skills?</summary><p>No. We run the servers, set up services automatically and handle technical support. If you can use a website, you can run your store.</p></details>
            <details><summary>Who handles support for my customers?</summary><p>Your customers contact you through your own branded support desk. You can answer simple questions yourself, and pass any technical ticket to our team with one click — we take it from there.</p></details>
            <details><summary>When and how do I get paid?</summary><p>Customer payments are received by us and credited to your reseller balance. Our cost is settled from it once a <?= e($period) ?>. Funds become withdrawable <?= e($holdingText) ?>, and once you have at least <?= e($minimum) ?> you can request a payout, which we send by bank transfer to the account you save.</p></details>
            <details><summary>Can I set my own prices?</summary><p>Yes. Set one markup for everything (up to <?= e($pct((float) $facts['maxMarkup'])) ?>) and override the price of any individual product. You can also run your own promo codes.</p></details>
            <details><summary>Do I need my own domain?</summary><p>Yes — your store runs on your own domain. You can register a new one during your application, or use one you already own.<?= $storeDomain !== null ? ' You also get a free ' . e($storeDomain) . ' address that works straight away.' : '' ?></p></details>
            <details><summary>Can my customers become resellers too?</summary><p>Yes. Your customers can open their own reseller websites with you as their supplier. They buy at your prices, and you earn on every sale they make.</p></details>
            <details><summary>What happens if a customer gets a refund?</summary><p>If a payment is refunded, the matching amount comes back off your balance. This is why payments are held for a short time before they can be withdrawn.</p></details>
        </div>
    </div>
</section>

<!-- ============================== CTA ============================== -->
<section class="fr-cta">
    <div class="fr-wrap">
        <h2>Ready to start your hosting business?</h2>
        <p>Apply in about two minutes. Your branded website is free — and you keep the profit on every sale.</p>
        <a class="fr-btn fr-btn--primary fr-btn--lg" href="<?= e($primaryHref) ?>"><?= e($primaryLabel) ?> <?= $icon('arrow') ?></a>
    </div>
</section>

</div>
<script src="<?= asset('assets/js/free-reseller.js') ?>" defer></script>
