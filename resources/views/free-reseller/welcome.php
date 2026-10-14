<?php
/**
 * After applying: what is done, and the exact next steps to go live.
 *
 * @var array<string, mixed> $client
 * @var array<string, mixed> $store
 * @var string $domain
 * @var string $mode 'register' | 'existing'
 * @var string|null $domainError
 * @var bool $domainInCart
 * @var string|null $recordName
 * @var string|null $recordValue
 * @var string $platformHost
 * @var string|null $platformUrl
 * @var string $domainStatus
 * @var bool $verified
 * @var array<string, mixed> $facts
 */
use CodeVault\Reseller\StorefrontIcons;

$icon = static fn (string $name): string => StorefrontIcons::svg($name, '');
$done = '<span class="fr-check__state fr-check__state--done">' . StorefrontIcons::svg('check', '') . '</span>';
$todo = static fn (int $n): string => '<span class="fr-check__state fr-check__state--todo">' . $n . '</span>';
$brand = (string) ($store['brand_name'] ?? $store['slug'] ?? 'Your store');
$approved = $domainStatus === 'approved';
$step = 2;
?>
<style>.cv-shell__main { padding: 0 !important; }</style>
<link rel="stylesheet" href="<?= asset('assets/css/free-reseller.css') ?>">

<div class="fr">
<section class="fr-welcome">
    <div class="fr-wrap">
        <div class="fr-welcome__hero">
            <div class="fr-welcome__badge"><?= $icon('check') ?></div>
            <h1>Welcome aboard, <?= e($brand) ?>!</h1>
            <p>Your free reseller website has been created. Follow the steps below to put it live on your domain.</p>
            <div class="fr-ids">
                <span>Reseller ID #<?= (int) $store['id'] ?></span>
                <span>Store ID: <?= e((string) ($store['slug'] ?? '')) ?></span>
                <?php if ($domain !== ''): ?><span>Domain: <?= e($domain) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="fr-check">
            <div class="fr-card fr-check__item">
                <?= $done ?>
                <div class="fr-check__body">
                    <h3>Reseller website created</h3>
                    <p>Your store is ready, with our full catalogue loaded.
                        <?php if ($platformUrl !== null): ?>
                            It already works at its free address: <a href="<?= e($platformUrl) ?>" target="_blank" rel="noopener" style="color:var(--cv-color-brand-500);font-weight:700;"><?= e($platformUrl) ?></a>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($domainError !== null && $domainError !== ''): ?>
                <div class="fr-card fr-check__item">
                    <?= $todo($step++) ?>
                    <div class="fr-check__body">
                        <h3>Add your domain</h3>
                        <p><?= e((string) $domainError) ?> Choose a domain for your store in Store settings.</p>
                        <a class="fr-btn fr-btn--primary" href="/client/reseller/store">Open Store settings <?= $icon('arrow') ?></a>
                    </div>
                </div>
            <?php elseif ($mode === 'register' && $domainInCart): ?>
                <div class="fr-card fr-check__item">
                    <?= $todo($step++) ?>
                    <div class="fr-check__body">
                        <h3>Pay for <?= e($domain) ?></h3>
                        <p>Your new domain is in your cart. Once it is paid, it is registered in your name and we connect it to your store — no DNS work needed on your side.</p>
                        <a class="fr-btn fr-btn--primary" href="/cart">Go to checkout <?= $icon('arrow') ?></a>
                    </div>
                </div>
            <?php elseif ($mode === 'register'): ?>
                <div class="fr-card fr-check__item">
                    <?= $verified ? $done : $todo($step++) ?>
                    <div class="fr-check__body">
                        <h3>Your domain <?= e($domain) ?></h3>
                        <p>
                            If you have paid for it, it is being registered and our team will connect it to your store.
                            Not paid yet? <a href="/domains/register?domain=<?= e(rawurlencode($domain)) ?>" style="color:var(--cv-color-brand-500);font-weight:700;">Add it to your cart again</a>.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="fr-card fr-check__item">
                    <?= $verified ? $done : $todo($step++) ?>
                    <div class="fr-check__body">
                        <h3><?= $verified ? 'Domain verified' : 'Point ' . e($domain) . ' at us' ?></h3>
                        <?php if ($verified): ?>
                            <p>We have confirmed you control <?= e($domain) ?>.</p>
                        <?php else: ?>
                            <p>At the company where you bought your domain, open its DNS settings and add <strong>one</strong> of these records. DNS changes can take from a few minutes to a few hours.</p>
                            <table class="fr-dns">
                                <thead><tr><th>Option</th><th>Type</th><th>Name / Host</th><th>Value / Points to</th></tr></thead>
                                <tbody>
                                <?php if ($recordName !== null && $recordValue !== null): ?>
                                    <tr><td>Recommended</td><td>TXT</td><td><span class="fr-code"><?= e($recordName) ?></span></td><td><span class="fr-code"><?= e($recordValue) ?></span></td></tr>
                                <?php endif; ?>
                                <tr><td>Or</td><td>CNAME</td><td><span class="fr-code"><?= e($domain) ?></span></td><td><span class="fr-code"><?= e($platformHost) ?></span></td></tr>
                                </tbody>
                            </table>
                            <p style="margin-top:12px;">Then press <strong>Verify</strong> in Store settings.</p>
                            <a class="fr-btn fr-btn--primary" href="/client/reseller/store">Verify my domain <?= $icon('arrow') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="fr-card fr-check__item">
                <?= $approved ? $done : $todo($step++) ?>
                <div class="fr-check__body">
                    <h3><?= $approved ? 'Domain approved and connected' : 'We approve and connect your domain' ?></h3>
                    <p><?= $approved
                        ? 'Your domain is approved. Once its free SSL certificate is issued, your website is live.'
                        : 'Our team reviews your domain, adds it to our servers and issues a free SSL certificate. You can follow progress on the go-live checklist in Store settings.' ?></p>
                </div>
            </div>
        </div>

        <div class="fr-next">
            <a class="fr-card" href="/client/reseller/store">
                <span class="fr-icon fr-icon--violet"><?= $icon('panel') ?></span>
                <h3>Brand your website</h3>
                <p>Logo, colours, favicon, homepage text and live chat.</p>
            </a>
            <a class="fr-card" href="/client/reseller/prices">
                <span class="fr-icon fr-icon--gold"><?= $icon('tag') ?></span>
                <h3>Set your prices</h3>
                <p>Choose your markup and fine-tune any product's price.</p>
            </a>
            <a class="fr-card" href="/client/reseller/account">
                <span class="fr-icon fr-icon--green"><?= $icon('wallet') ?></span>
                <h3>Add your bank account</h3>
                <p>So we can pay out your profit (from <?= e((string) $facts['payoutMinimum'] . ' ' . (string) $facts['payoutCurrency']) ?>).</p>
            </a>
        </div>
        <p style="text-align:center;margin-top:26px;"><a class="fr-btn fr-btn--outline" href="/client/reseller">Go to my Reseller Area</a></p>
    </div>
</section>
</div>
