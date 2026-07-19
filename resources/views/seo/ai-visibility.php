<?php
/** @var array<int, array{path: string, score: int, checks: array<string, bool>, error: ?string}> $results */
/** @var int $overall */
/** @var int $truncatedProducts */
/** @var int $truncatedArticles */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">AI Visibility Score</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <p style="color:var(--cv-text-secondary);">Each public page is fetched live and checked for structured data, a canonical tag, a well-sized meta description, and a single H1 — the standard signals crawlers and AI answer engines look for. Not a proprietary rubric — a generic best-practice check.</p>
    <p style="font-size:var(--cv-text-lg);font-weight:700;margin-top:var(--cv-space-3);">Overall: <?= $overall ?>/100</p>
    <?php if ($truncatedProducts > 0 || $truncatedArticles > 0): ?>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            Showing the first 20 of each — <?= $truncatedProducts ?> more product(s) and <?= $truncatedArticles ?> more article(s) not scored on this run.
        </p>
    <?php endif; ?>
</div>

<div class="cv-card">
    <table class="cv-table">
        <thead><tr><th>Page</th><th>Score</th><th>JSON-LD</th><th>Canonical</th><th>Meta Description</th><th>Single H1</th></tr></thead>
        <tbody>
        <?php foreach ($results as $result): ?>
            <tr>
                <td><a href="<?= e($result['path']) ?>" target="_blank"><?= e($result['path']) ?></a></td>
                <td>
                    <?php if ($result['error'] !== null): ?>
                        <span class="cv-badge cv-badge--danger">Error</span>
                    <?php elseif ($result['score'] === 100): ?>
                        <span class="cv-badge cv-badge--success"><?= $result['score'] ?></span>
                    <?php elseif ($result['score'] >= 50): ?>
                        <span class="cv-badge cv-badge--neutral"><?= $result['score'] ?></span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger"><?= $result['score'] ?></span>
                    <?php endif; ?>
                </td>
                <?php foreach ($result['checks'] as $passed): ?>
                    <td><?= $passed ? '✓' : '✗' ?></td>
                <?php endforeach; ?>
            </tr>
            <?php if ($result['error'] !== null): ?>
                <tr><td colspan="6" style="color:var(--cv-text-secondary);"><?= e($result['error']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($results === []): ?>
            <tr><td colspan="6" style="color:var(--cv-text-secondary);">No public pages to score.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
