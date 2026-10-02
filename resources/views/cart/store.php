<?php
/**
 * Storefront — the front page of a store.
 *
 * This page is served on a reseller's own domain, so:
 *
 *  - All styling lives in /assets/css/store.css and is driven by
 *    --cv-color-brand-500/600, which layouts/client.php overrides per tenant.
 *    Nothing here may hard-code a brand colour, or a reseller who chooses red
 *    still sees the platform's palette.
 *
 *  - No platform branding, vendor names, or invented product claims. The
 *    previous version decided a product's feature bullets by substring-matching
 *    its NAME ("google" => "30GB Secure Cloud Storage"), which puts made-up
 *    specifications in front of a buyer, and it branched on the literal string
 *    'ResellerClub Email Hosting' — the platform's upstream vendor appearing on
 *    a reseller's own storefront.
 *
 * @var array<int, array<string, mixed>> $groups
 * @var CodeVault\Localization\Translation $t
 * @var array{brandName?: string|null, logoUrl?: string|null} $theme
 */
// View::render() only injects 'theme' when it was constructed with a
// ThemeSettings, so the key can be absent entirely — reading an offset off an
// undefined variable is a warning, and a warning printed before a header()
// turns "one missing brand name" into a broken page.
$theme ??= [];

$brandName = trim((string) ($theme['brandName'] ?? ''));

// A customer-facing catalogue should not advertise a category that has nothing
// in it, so a group with no plans is skipped entirely. This is also what lets
// the hero's quick links promise nothing the page cannot keep: the same list
// drives both, so a link can never point at a heading that renders as empty.
$renderableGroups = [];
$linkedGroups = [];
foreach ($groups as $candidate) {
    if (((array) ($candidate['products'] ?? [])) !== []) {
        $renderableGroups[] = $candidate;
        $linkedGroups[(int) $candidate['id']] = (string) ($candidate['name'] ?? '');
    }
}

/**
 * A generic glyph for a group. Keyed on what the group sells, never on a
 * vendor's name.
 */
$groupIcon = static function (string $name): string {
    $lower = strtolower($name);
    if (str_contains($lower, 'domain')) {
        return '🌐';
    }
    if (str_contains($lower, 'email') || str_contains($lower, 'mail')) {
        return '✉️';
    }
    if (str_contains($lower, 'hosting') || str_contains($lower, 'server')) {
        return '🖥️';
    }

    return '◆';
};
?>
<link rel="stylesheet" href="/assets/css/store.css">

<div class="store-shell">
    <section class="store-hero">
        <div class="store-hero__inner">
            <div class="store-hero__content">
                <?php if ($brandName !== ''): ?>
                    <span class="store-hero__eyebrow"><?= e($brandName) ?></span>
                <?php endif; ?>
                <h1 class="store-hero__title">Hosting, domains and email</h1>
                <p class="store-hero__subtitle">
                    Browse the plans below and order in a few clicks. Prices are shown in the
                    currency selected for this store, and every plan is activated from your
                    client area.
                </p>
                <div class="store-hero__actions">
                    <?php if ($linkedGroups !== []): ?>
                        <a href="#plans" class="store-hero__cta">
                            <span>Browse plans</span>
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endif; ?>
                    <?php
                    // Was a link to /client/services, which needs a signed-in
                    // session: a first-time visitor clicking the hero button on a
                    // storefront landed on a login wall instead of the plans.
                    ?>
                    <a href="/client/login" class="store-hero__link">
                        <span>Client area sign in</span>
                    </a>
                </div>
            </div>

            <?php if ($linkedGroups !== []): ?>
                <nav class="store-hero__aside" aria-label="Product groups">
                    <span class="store-hero__aside-label">On this page</span>
                    <?php foreach ($linkedGroups as $linkedId => $linkedName): ?>
                        <a class="store-hero__aside-item" href="#group-<?= (int) $linkedId ?>">
                            <span class="store-hero__aside-icon" aria-hidden="true">&#8595;</span>
                            <span><?= e($linkedName) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($renderableGroups === []): ?>
        <div class="store-empty">
            <div class="store-empty__icon" aria-hidden="true">&#128230;</div>
            <h2 class="store-empty__title">No products available</h2>
            <p class="store-empty__text"><?= e($t->get('store.no_products')) ?></p>
        </div>
    <?php else: ?>
        <div id="plans">
            <?php foreach ($renderableGroups as $group): ?>
                <?php $groupProducts = (array) ($group['products'] ?? []); ?>
                <section class="store-group" id="group-<?= (int) $group['id'] ?>">
                    <div class="store-group__header">
                        <h2 class="store-group__title">
                            <span aria-hidden="true"><?= $groupIcon((string) ($group['name'] ?? '')) ?></span>
                            <span><?= e((string) ($group['name'] ?? '')) ?></span>
                            <span class="store-group__count">
                                <?= count($groupProducts) ?> <?= count($groupProducts) === 1 ? 'plan' : 'plans' ?>
                            </span>
                        </h2>
                        <?php if (!empty($group['description'])): ?>
                            <p class="store-group__description"><?= e((string) $group['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="store-grid">
                            <?php foreach ($groupProducts as $product): ?>
                                <article class="store-card">
                                    <h3 class="store-card__name"><?= e((string) ($product['name'] ?? '')) ?></h3>

                                    <?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
                                        <div class="store-card__description">
                                            <?php
                                            // Descriptions are typed in a plain textarea, so the
                                            // line breaks that separate points must survive into
                                            // HTML — escaping alone collapsed them into one run-on
                                            // block.
                                            ?>
                                            <?= CodeVault\Support\FormattedText::toHtml((string) $product['description']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="store-card__price">
                                        <div class="store-card__price-label">Starting at</div>
                                        <div class="store-card__price-amount">
                                            <?= e($money((float) ($product['starting_price'] ?? 0))) ?>
                                        </div>
                                        <div class="store-card__price-cycle">
                                            <?= e(ucfirst(str_replace('_', ' ', (string) ($product['starting_cycle'] ?? 'monthly')))) ?>
                                        </div>
                                    </div>

                                    <a href="/store/<?= (int) $product['id'] ?>" class="store-card__cta">
                                        <span>Select plan</span>
                                        <span aria-hidden="true">&rarr;</span>
                                    </a>
                                </article>
                            <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
