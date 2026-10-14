<?php
/**
 * @var array<string, mixed> $store
 * @var float $markup
 * @var array<int, array<string, mixed>> $services
 * @var array<int, array<string, mixed>> $domains
 * @var string|null $error
 * @var string|null $notice
 * @var array{service: float, domain: float} $discounts
 * @var array<string, mixed> $currency
 * @var array<string, mixed>|null $upline the store this reseller registered under (a sub-reseller buys at its prices)
 */

$upline = $upline ?? null;
$uplineName = $upline === null ? '' : (trim((string) ($upline['brand_name'] ?? '')) !== '' ? (string) $upline['brand_name'] : (string) $upline['slug']);

$servicePct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['service']);
$domainPct = \CodeVault\Reseller\ResellerSettings::formatPercent($discounts['domain']);
$currencyCode = (string) $currency['code'];
?>
<div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Your prices</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <div class="cv-alert cv-alert--neutral">
        <strong>These prices are not live yet.</strong> They are what your customers will be charged once your
        store starts taking orders. Until then the public shop still shows the platform's own prices, so
        don't advertise these figures yet.
    </div>

    <?php if ($upline !== null): ?>
    <p>You buy at <strong><?= e($uplineName) ?></strong>'s prices (the provider you registered with). Your markup is
        added on top of their price, so your markup is your margin. Your customers cannot open reseller accounts.</p>
    <?php else: ?>
    <p>You pay <strong><?= e($servicePct) ?>%</strong> below list on services and
        <strong><?= e($domainPct) ?>%</strong> below list on domains. Your markup is added on top of the list
        price, so your margin is the markup plus that discount.</p>
    <?php endif; ?>
    <p style="color:var(--cv-text-secondary);">All figures in <?= e($currencyCode) ?>, the currency your
        catalogue is priced in.</p>
</div>

<form method="post" action="/client/reseller/prices">
    <?= csrf_field() ?>

    <div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Store-wide markup</h2>
        <?php if ($upline !== null): ?>
        <p>Added to every price you have not set by hand. <strong>0%</strong> means you charge exactly what you
            pay, with no margin — set a markup to earn on each sale.</p>
        <?php else: ?>
        <p>Added to every price you have not set by hand. <strong>0%</strong> means you charge the platform's
            list price and keep the reseller discount as your margin.</p>
        <?php endif; ?>
        <p>
            <label for="markup_percent">Markup (%)</label><br>
            <input class="cv-input" type="number" id="markup_percent" name="markup_percent"
                   min="0" max="1000" step="0.01" value="<?= e(\CodeVault\Reseller\ResellerSettings::formatPercent($markup)) ?>">
        </p>
    </div>

    <div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Services</h2>
        <p style="color:var(--cv-text-secondary);">Leave a price blank to use your markup.</p>
        <table class="cv-table">
            <thead>
            <tr>
                <th>Service</th><th>Cycle</th><th><?= $upline !== null ? 'Their price' : 'List' ?></th><th>You pay</th>
                <th>Your price</th><th>Your margin</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($services as $product): ?>
                <?php foreach ($product['cycles'] as $cycle): ?>
                    <?php
                    $key = (int) $product['product_id'] . ':' . (string) $cycle['cycle'];
                    $q = $cycle['retail'];
                    $display = $cycle['retail_display'];
                    ?>
                    <tr>
                        <td><?= e((string) $product['name']) ?></td>
                        <td><?= e((string) $cycle['label']) ?></td>
                        <td><?= e((string) $display['list_display']) ?></td>
                        <td><?= e((string) $display['cost_display']) ?></td>
                        <td>
                            <input class="cv-input" type="number" step="0.01" min="0"
                                   name="price[<?= e($key) ?>]"
                                   placeholder="<?= e((string) $display['retail_display']) ?>"
                                   value="<?= $q['overridden'] ? e(number_format((float) $q['retail'], 2, '.', '')) : '' ?>">
                            <br>
                            <?php if ($display['below_cost']): ?>
                                <span class="cv-badge cv-badge--danger">below your cost</span>
                            <?php else: ?>
                                <span style="color:var(--cv-text-secondary);">
                                    <?= $q['overridden'] ? 'set by you' : 'from markup' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) $display['margin_display']) ?>
                            <?php if ((float) $q['list'] > 0): ?>
                                <br><span style="color:var(--cv-text-secondary);">
                                    <?= e(number_format((float) $q['margin'] / (float) $q['list'] * 100, 1)) ?>% of list
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($services === []): ?>
                <tr><td colspan="6" style="color:var(--cv-text-secondary);">No services are available to resell yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cv-card" style="max-width:64rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Domains</h2>
        <p style="color:var(--cv-text-secondary);">Leave a price blank to use your markup. Each of the three can
            be set independently — many resellers discount registration and make it back on renewal.</p>
        <table class="cv-table">
            <thead>
            <tr><th>TLD</th><th>Type</th><th><?= $upline !== null ? 'Their price' : 'List' ?></th><th>You pay</th><th>Your price</th><th>Your margin</th></tr>
            </thead>
            <tbody>
            <?php foreach ($domains as $row): ?>
                <?php foreach (['register' => 'Register', 'transfer' => 'Transfer', 'renew' => 'Renew'] as $which => $label): ?>
                    <?php
                    $q = $row[$which . '_retail'];
                    $display = $row[$which . '_display'];
                    ?>
                    <tr>
                        <td><?= $which === 'register' ? e((string) $row['tld']) : '' ?></td>
                        <td><?= e($label) ?></td>
                        <td><?= e((string) $display['list_display']) ?></td>
                        <td><?= e((string) $display['cost_display']) ?></td>
                        <td>
                            <input class="cv-input" type="number" step="0.01" min="0"
                                   name="domain[<?= e((string) $row['tld']) ?>.<?= e($which) ?>]"
                                   placeholder="<?= e((string) $display['retail_display']) ?>"
                                   value="<?= $q['overridden'] ? e(number_format((float) $q['retail'], 2, '.', '')) : '' ?>">
                            <?php if ($display['below_cost']): ?>
                                <span class="cv-badge cv-badge--danger">below your cost</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $display['margin_display']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($domains === []): ?>
                <tr><td colspan="6" style="color:var(--cv-text-secondary);">No TLD pricing is configured yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cv-card" style="max-width:64rem;margin:0 auto;">
        <button class="cv-btn" type="submit">Save my prices</button>
        <p style="color:var(--cv-text-secondary);">A price below what you pay us is refused: you would owe more
            than you collect. Remove a price to fall back to your markup.</p>
    </div>
</form>
