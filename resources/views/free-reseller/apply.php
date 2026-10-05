<?php
/**
 * The Free Reseller application form (main website only).
 *
 * @var array<string, mixed>|null $client
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var bool $resume
 * @var bool $hasDraft
 * @var string|null $slugSuggestion
 * @var string|null $storeDomain
 * @var string $platformHost
 * @var array<int, array<string, mixed>> $domainPrices
 * @var array<string, mixed> $facts
 */
use CodeVault\Reseller\StorefrontIcons;

$icon = static fn (string $name): string => StorefrontIcons::svg($name, '');
$err = static fn (string $field): string => isset($errors[$field]) ? '<div class="fr-error" role="alert">' . e($errors[$field]) . '</div>' : '';
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$isGuest = $client === null;
$mode = (string) $values['domain_mode'] === 'existing' ? 'existing' : 'register';
$name = $isGuest ? '' : trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
$initial = $name !== '' ? strtoupper(substr($name, 0, 1)) : '?';
$tldList = array_map(static fn (array $t): string => '.' . ltrim((string) $t['tld'], '.'), $domainPrices);
$defaultTld = in_array('.com', $tldList, true) || $tldList === [] ? '.com' : $tldList[0];

$holdingDays = (int) $facts['holdingDays'];
?>
<style>.cv-shell__main { padding: 0 !important; }</style>
<link rel="stylesheet" href="<?= asset('assets/css/free-reseller.css') ?>">

<div class="fr">
<section class="fr-apply">
    <div class="fr-wrap">
        <div class="fr-apply__title">
            <a href="/free-reseller" style="color:var(--cv-text-secondary);text-decoration:none;font-weight:600;font-size:.9rem;">&larr; Free Reseller Programme</a>
            <h1>Get your free reseller website</h1>
            <p>Three short sections and you are done. Your website is free — you only pay for a new domain if you register one.</p>
        </div>

        <ol class="fr-progress" aria-label="Application steps">
            <li class="<?= $isGuest ? '' : 'is-done' ?>"><b><?= $isGuest ? '1' : '✓' ?></b> Your account</li>
            <li><b>2</b> Your business</li>
            <li><b>3</b> Your domain</li>
            <li><b>4</b> Go live</li>
        </ol>

        <?php if ($resume): ?>
            <div class="fr-alert fr-alert--ok" role="status">
                <strong>Welcome<?= $name !== '' ? ', ' . e($name) : '' ?>! Your account is ready.</strong>
                We kept your answers — check them below and press <strong>Create my reseller website</strong> to finish.
                <form method="post" action="/free-reseller/apply/discard" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="fr-link-btn">Not now</button>
                </form>
            </div>
        <?php elseif ($isGuest && $hasDraft): ?>
            <div class="fr-alert fr-alert--info" role="status">
                Your answers are saved. Create your account or sign in below to finish your application.
                <form method="post" action="/free-reseller/apply/discard" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="fr-link-btn">Start again</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="fr-alert fr-alert--error" role="alert">
                <strong>Please check the highlighted fields.</strong>
                <?= count($errors) === 1 ? e((string) array_values($errors)[0]) : count($errors) . ' things need your attention.' ?>
            </div>
        <?php endif; ?>

        <div class="fr-apply__grid">
            <form method="post" action="/free-reseller/apply" novalidate data-fr-apply>
                <?= csrf_field() ?>

                <!-- 1. Account -->
                <div class="fr-card fr-panel">
                    <div class="fr-panel__head">
                        <span class="fr-panel__num">1</span>
                        <div><h2>Your account</h2><p>Your reseller business is linked to your client account.</p></div>
                    </div>
                    <div class="fr-panel__body">
                        <?php if ($isGuest): ?>
                            <p style="color:var(--cv-text-secondary);font-size:.95rem;">
                                Fill in your business and domain below. At the end you will <strong>create your free account</strong>
                                (or sign in if you already have one) — your answers are kept, and you come straight back here to finish.
                            </p>
                        <?php else: ?>
                            <div class="fr-account__who">
                                <span class="fr-account__avatar"><?= e($initial) ?></span>
                                <div>
                                    <strong><?= e($name !== '' ? $name : (string) ($client['email'] ?? '')) ?></strong>
                                    <span><?= e((string) ($client['email'] ?? '')) ?> &middot; Client ID #<?= (int) $client['id'] ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Business -->
                <div class="fr-card fr-panel">
                    <div class="fr-panel__head">
                        <span class="fr-panel__num">2</span>
                        <div><h2>Your business</h2><p>What your customers will see. You can change the look any time.</p></div>
                    </div>
                    <div class="fr-panel__body">
                        <div class="fr-field">
                            <label for="fr-store-name">Business name</label>
                            <input class="fr-input<?= $invalid('store_name') ?>" id="fr-store-name" name="store_name" maxlength="80" required
                                   value="<?= e((string) $values['store_name']) ?>" placeholder="e.g. Sunrise Hosting" autocomplete="organization" data-fr-store-name>
                            <?= $err('store_name') ?>
                        </div>
                        <div class="fr-field">
                            <label for="fr-slug">Store ID</label>
                            <div class="fr-affix<?= $invalid('slug') ?>">
                                <?php if ($storeDomain === null): ?><span class="fr-affix__fix">ID</span><?php endif; ?>
                                <input class="fr-input" id="fr-slug" name="slug" maxlength="63"
                                       value="<?= e((string) $values['slug']) ?>" placeholder="<?= e($slugSuggestion ?? 'sunrise-hosting') ?>"
                                       autocomplete="off" spellcheck="false" data-fr-slug>
                                <?php if ($storeDomain !== null): ?><span class="fr-affix__fix">.<?= e($storeDomain) ?></span><?php endif; ?>
                            </div>
                            <small>
                                <?= $storeDomain !== null
                                    ? 'Your free address while your domain connects. Lowercase letters, numbers and hyphens.'
                                    : 'A short, unique name for your store inside our system. Lowercase letters, numbers and hyphens.' ?>
                                Leave it blank to use your business name.
                            </small>
                            <?= $err('slug') ?>
                        </div>
                    </div>
                </div>

                <!-- 3. Domain -->
                <div class="fr-card fr-panel">
                    <div class="fr-panel__head">
                        <span class="fr-panel__num">3</span>
                        <div><h2>Your domain</h2><p>Your website runs on your own domain name.</p></div>
                    </div>
                    <div class="fr-panel__body">
                        <div class="fr-choice" role="radiogroup" aria-label="Domain option">
                            <label class="fr-choice__opt">
                                <input type="radio" name="domain_mode" value="register" <?= $mode === 'register' ? 'checked' : '' ?> data-fr-mode>
                                <span class="fr-choice__radio"></span>
                                <div><strong>Register a new domain</strong><span>Search, then pay for it at checkout. We connect it for you.</span></div>
                            </label>
                            <label class="fr-choice__opt">
                                <input type="radio" name="domain_mode" value="existing" <?= $mode === 'existing' ? 'checked' : '' ?> data-fr-mode>
                                <span class="fr-choice__radio"></span>
                                <div><strong>I already own a domain</strong><span>Use a domain you bought anywhere. Free.</span></div>
                            </label>
                        </div>

                        <div class="fr-pane" data-fr-pane="register" <?= $mode === 'register' ? '' : 'hidden' ?>>
                            <div class="fr-field">
                                <label for="fr-new-domain">Find your domain</label>
                                <div class="fr-search">
                                    <input class="fr-input<?= $invalid('new_domain') ?>" id="fr-new-domain" name="new_domain"
                                           value="<?= e((string) $values['new_domain']) ?>" placeholder="mybrand<?= e($defaultTld) ?>"
                                           autocomplete="off" spellcheck="false" inputmode="url"
                                           data-fr-domain-input data-default-tld="<?= e($defaultTld) ?>">
                                    <button type="button" class="fr-btn fr-btn--outline" data-fr-domain-search><?= $icon('search') ?> Check</button>
                                </div>
                                <?= $err('new_domain') ?>
                            </div>
                            <div class="fr-result" data-fr-domain-result aria-live="polite">
                                <span class="fr-result__icon" data-fr-result-icon></span>
                                <div class="fr-result__text"><b data-fr-result-name></b><span data-fr-result-msg></span></div>
                                <span class="fr-result__price" data-fr-result-price></span>
                            </div>
                            <?php if ($domainPrices !== []): ?>
                                <div class="fr-tlds" style="justify-content:flex-start;margin-top:12px;">
                                    <?php foreach ($domainPrices as $tld): ?>
                                        <?php $ext = '.' . ltrim((string) $tld['tld'], '.'); ?>
                                        <button type="button" class="fr-tld" style="cursor:pointer;font:inherit;font-weight:700;font-size:.85rem;" data-fr-tld="<?= e($ext) ?>">
                                            <?= e($ext) ?><span><?= e((string) $tld['formatted_price']) ?>/yr</span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <small style="display:block;color:var(--cv-text-secondary);font-size:.82rem;margin-top:10px;">
                                The domain goes into your cart. After you finish here, you pay for it at checkout and it is registered in your name.
                            </small>
                        </div>

                        <div class="fr-pane" data-fr-pane="existing" <?= $mode === 'existing' ? '' : 'hidden' ?>>
                            <div class="fr-field">
                                <label for="fr-existing-domain">Your domain</label>
                                <input class="fr-input<?= $invalid('existing_domain') ?>" id="fr-existing-domain" name="existing_domain"
                                       value="<?= e((string) $values['existing_domain']) ?>" placeholder="mybrand.com"
                                       autocomplete="off" spellcheck="false" inputmode="url">
                                <small>After you apply we show you the exact DNS record to add at your domain provider — a TXT record, or a CNAME pointing to <span class="fr-code"><?= e($platformHost) ?></span>. Our team then approves it and issues a free SSL certificate.</small>
                                <?= $err('existing_domain') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Confirm -->
                <div class="fr-card fr-panel">
                    <div class="fr-panel__head">
                        <span class="fr-panel__num">4</span>
                        <div><h2>Go live</h2><p>One last check.</p></div>
                    </div>
                    <div class="fr-panel__body">
                        <label class="fr-agree">
                            <input type="checkbox" name="agree" value="1" <?= !empty($values['agree']) ? 'checked' : '' ?>>
                            <span>
                                I agree to the reseller terms: I set my own prices and look after my customers; customer payments are
                                collected by the platform and credited to my balance; and payouts follow the
                                <a href="/free-reseller#payouts" target="_blank" rel="noopener">programme rules</a>
                                (<?= $holdingDays === 0 ? 'no holding period' : $holdingDays . '-day holding period' ?>, minimum payout <?= e((string) $facts['payoutMinimum'] . ' ' . (string) $facts['payoutCurrency']) ?>, paid by bank transfer).
                            </span>
                        </label>
                        <?= $err('agree') ?>

                        <div class="fr-submit">
                            <?php if ($isGuest): ?>
                                <div class="fr-submit__row">
                                    <button type="submit" name="account_action" value="register" class="fr-btn fr-btn--primary fr-btn--block fr-btn--lg">Create free account &amp; continue</button>
                                    <button type="submit" name="account_action" value="login" class="fr-btn fr-btn--outline fr-btn--block fr-btn--lg">I have an account — sign in</button>
                                </div>
                                <small style="color:var(--cv-text-secondary);font-size:.84rem;text-align:center;">Your answers are saved while you create your account or sign in.</small>
                            <?php else: ?>
                                <button type="submit" class="fr-btn fr-btn--primary fr-btn--block fr-btn--lg" data-fr-submit>
                                    Create my reseller website <?= $icon('arrow') ?>
                                </button>
                                <small style="color:var(--cv-text-secondary);font-size:.84rem;text-align:center;" data-fr-submit-note>
                                    <?= $mode === 'register' ? 'Your new domain will be waiting in your cart, ready to pay.' : 'Free — nothing to pay.' ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <aside class="fr-aside">
                <div class="fr-card">
                    <h3>What you get — free</h3>
                    <ul>
                        <li><?= $icon('check') ?> A fully branded hosting website</li>
                        <li><?= $icon('check') ?> VPS, dedicated servers, hosting &amp; domains to sell</li>
                        <li><?= $icon('check') ?> Your own prices and promo codes</li>
                        <li><?= $icon('check') ?> Customer management &amp; support desk</li>
                        <li><?= $icon('check') ?> Technical support handled by our team</li>
                        <li><?= $icon('check') ?> Earnings dashboard &amp; bank payouts</li>
                        <li><?= $icon('check') ?> Resellers of your own (master reseller)</li>
                    </ul>
                </div>
                <div class="fr-card">
                    <h3>How you get paid</h3>
                    <ul>
                        <li><?= $icon('wallet') ?> Customers pay on your website; we credit your balance</li>
                        <li><?= $icon('lock') ?> <?= $holdingDays === 0 ? 'Withdraw your profit straight away' : 'Withdrawable after ' . $holdingDays . ' days' ?></li>
                        <li><?= $icon('transfer') ?> Bank transfer from <?= e((string) $facts['payoutMinimum'] . ' ' . (string) $facts['payoutCurrency']) ?></li>
                    </ul>
                    <p style="margin-top:12px;font-size:.88rem;"><a href="/free-reseller#faq" style="color:var(--cv-color-brand-500);font-weight:700;">Read the FAQ &rarr;</a></p>
                </div>
            </aside>
        </div>
    </div>
</section>
</div>
<script src="<?= asset('assets/js/free-reseller.js') ?>" defer></script>
