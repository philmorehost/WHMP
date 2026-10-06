<?php
/**
 * The storefront footer — every page of a reseller's website.
 *
 * Four columns like a hosting template (brand, services, domains & account,
 * contact) over a call-to-action strip. Everything in it is the STORE's: its
 * name, its categories, its support address and WhatsApp. No platform links.
 *
 * @var array<string, mixed>|null $store
 * @var array<string, mixed>|null $theme
 * @var CodeVault\Localization\Translation|null $t
 * @var array<int, array<string, mixed>>|null $categories  injectable for tests
 * @var bool|null $platform                                true ONLY from the main-website branch of layouts/client.php
 * @var array<string, string>|null $contact                injectable for tests (platform mode)
 *
 * Platform mode is the MAIN website wearing this footer: the company's own
 * contact details and its extra pages (Knowledgebase, network status, Affiliates,
 * Free Reseller, terms). Never set on a store's host.
 */
use CodeVault\Reseller\StorefrontIcons as Icon;

$store ??= [];
$theme ??= [];
$t ??= null;
$categories ??= null;
$platform = (bool) ($platform ?? false);
$contact ??= null;

if ($categories === null) {
    try {
        $categories = \CodeVault\Support\App::container()->make(\CodeVault\Reseller\StorefrontCatalogue::class)->categories();
    } catch (\Throwable) {
        $categories = [];
    }
}

$brandName = trim((string) ($theme['brandName'] ?? ($store['brand_name'] ?? ''))) ?: brand_name();
$supportEmail = trim((string) ($store['support_email'] ?? ''));
$whatsapp = '';
$phone = '';
$address = '';
$freeResellerLink = false;
$termsUrl = '';

if ($platform) {
    // The platform's own company details — never a store's, never on a store.
    $contact ??= \CodeVault\Theme\PlatformSite::contact();
    $supportEmail = trim((string) ($contact['email'] ?? ''));
    $whatsapp = (string) ($contact['whatsapp'] ?? '');
    $phone = trim((string) ($contact['phone'] ?? ''));
    $address = trim((string) ($contact['address'] ?? ''));
    $freeResellerLink = \CodeVault\Reseller\FreeResellerProgramme::advertFor(\CodeVault\Reseller\FreeResellerProgramme::PLACEMENT_NAV) !== null;
    $termsUrl = trim((string) ($theme['termsUrl'] ?? '')) ?: '/terms';
    $tagline = \CodeVault\Theme\PlatformSite::taglineFor(\CodeVault\Theme\PlatformSite::setting(\CodeVault\Theme\PlatformSite::TAGLINE_KEY));
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

    $tagline = \CodeVault\Reseller\StorefrontHome::taglineFor($store);
}
?>
<footer class="sf-footer">
    <div class="sf-container">
        <div class="sf-footer__cta">
            <div>
                <h2>Ready to get your website online?</h2>
                <p>Pick a plan, add a domain and you are live — all from one place.</p>
            </div>
            <div class="sf-footer__cta-actions">
                <a href="/store" class="sf-btn sf-btn--light">View plans <?= Icon::svg('arrow', 'sf-icon sf-icon--xs') ?></a>
                <a href="/domains/register" class="sf-btn sf-btn--outline-light">Find a domain</a>
            </div>
        </div>

        <div class="sf-footer__grid<?= $platform ? ' sf-footer__grid--platform' : '' ?>">
            <div class="sf-footer__brand">
                <a href="/" class="sf-logo sf-logo--footer">
                    <span class="sf-logo__mark" aria-hidden="true"><?= Icon::svg('cloud') ?></span>
                    <span class="sf-logo__text"><?= e($brandName) ?></span>
                </a>
                <p><?= e(mb_strimwidth($tagline, 0, 180, '…')) ?></p>
            </div>

            <div>
                <h3>Services</h3>
                <ul>
                    <?php foreach (array_slice((array) $categories, 0, 6) as $category): ?>
                        <li><a href="/store?group_id=<?= (int) $category['id'] ?>"><?= e((string) $category['name']) ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="/store">All services</a></li>
                </ul>
            </div>

            <div>
                <h3>Domains &amp; account</h3>
                <ul>
                    <li><a href="/domains/register">Register a domain</a></li>
                    <li><a href="/domains/transfer">Transfer a domain</a></li>
                    <li><a href="/deals">Deals</a></li>
                    <li><a href="/client/dashboard">Client area</a></li>
                    <li><a href="/client/register">Create an account</a></li>
                </ul>
            </div>

            <?php if ($platform): ?>
                <div>
                    <h3>Company</h3>
                    <ul>
                        <?php if ($freeResellerLink): ?>
                            <li><a href="/free-reseller">Free Reseller programme</a></li>
                        <?php endif; ?>
                        <li><a href="/client/affiliate">Affiliates</a></li>
                        <li><a href="/kb">Knowledgebase</a></li>
                        <li><a href="/status">Network status</a></li>
                        <li><a href="<?= e($termsUrl) ?>">Terms of service</a></li>
                    </ul>
                </div>
            <?php endif; ?>

            <div>
                <h3>Get in touch</h3>
                <ul class="sf-footer__contact">
                    <?php if ($supportEmail !== ''): ?>
                        <li><a href="mailto:<?= e($supportEmail) ?>"><?= Icon::svg('mail') ?><span><?= e($supportEmail) ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($whatsapp !== ''): ?>
                        <li><a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= Icon::svg('phone') ?><span>+<?= e($whatsapp) ?> (WhatsApp)</span></a></li>
                    <?php endif; ?>
                    <?php if ($phone !== '' && $platform && \CodeVault\Theme\PlatformSite::digits($phone) !== $whatsapp): ?>
                        <li><a href="tel:<?= e((string) preg_replace('/[^\d+]/', '', $phone)) ?>"><?= Icon::svg('headset') ?><span><?= e($phone) ?></span></a></li>
                    <?php endif; ?>
                    <li><a href="/client/tickets/create"><?= Icon::svg('ticket') ?><span>Open a support ticket</span></a></li>
                    <?php if ($address !== ''): ?>
                        <li class="sf-footer__address"><?= Icon::svg('globe') ?><span><?= nl2br(e($address)) ?></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="sf-footer__bottom">
            <span>&copy; <?= date('Y') ?> <?= e($brandName) ?>. <?= e($t?->get('footer.rights') ?? 'All rights reserved.') ?></span>
            <span class="sf-footer__secure"><?= Icon::svg('lock') ?> Secure checkout</span>
        </div>
    </div>
</footer>
