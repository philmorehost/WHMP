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
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null];
?>
<header class="cv-topbar" style="display:flex;align-items:center;justify-content:space-between;padding:var(--cv-space-4) var(--cv-space-6);border-bottom:1px solid var(--cv-border-default);">
    <strong style="display:flex;align-items:center;gap:var(--cv-space-2);">
        <?php if (!empty($theme['logoUrl'])): ?>
            <img src="<?= e($theme['logoUrl']) ?>" alt="<?= e($theme['brandName']) ?>" style="height:1.75rem;">
        <?php endif; ?>
        <?= e($title ?? $theme['brandName']) ?>
    </strong>
    <div class="cv-topbar__actions" style="display:flex;gap:var(--cv-space-3);align-items:center;">
        <?php if ($languages !== null && $t !== null && count($languages) > 1): ?>
            <form method="post" action="/language" style="margin:0;"><?= csrf_field() ?>
                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? '/store') ?>">
                <select class="cv-input" name="language_id" data-auto-submit aria-label="Select language">
                    <?php foreach ($languages as $language): ?>
                        <option value="<?= (int) $language['id'] ?>" <?= (int) $language['id'] === $t->languageId() ? 'selected' : '' ?>>
                            <?= e($language['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($currencies !== null && $selectedCurrency !== null && count($currencies) > 1): ?>
            <form method="post" action="/currency" style="margin:0;"><?= csrf_field() ?>
                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? '/store') ?>">
                <select class="cv-input" name="currency_id" data-auto-submit aria-label="Select display currency">
                    <?php foreach ($currencies as $currency): ?>
                        <option value="<?= (int) $currency['id'] ?>" <?= (int) $currency['id'] === (int) $selectedCurrency['id'] ? 'selected' : '' ?>>
                            <?= e($currency['code']) ?> (<?= e($currency['symbol']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <button type="button" class="cv-theme-toggle" data-theme-toggle aria-label="Toggle dark mode">
            <svg class="cv-icon-moon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 8.7A5.5 5.5 0 0 1 7.3 2.5a5.5 5.5 0 1 0 6.2 6.2z"/></svg>
            <svg class="cv-icon-sun" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M2.5 8H1M15 8h-1.5M3.5 3.5l1 1M11.5 11.5l1 1M12.5 3.5l-1 1M4.5 11.5l-1 1"/></svg>
        </button>
    </div>
</header>
