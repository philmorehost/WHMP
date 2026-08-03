<?php
/** @var array<string, mixed> $article */
/** @var array<int, array<string, mixed>> $images */

use CodeVault\Knowledgebase\KbArticleRenderer;

$id = (int) $article['id'];
$images ??= [];
?>
<div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($article['title']) ?></h1>
    <p><a href="/kb">&larr; Back to Knowledgebase</a></p>
    <p style="color:var(--cv-text-secondary);"><?= e($article['category_name']) ?> &middot; <?= (int) $article['views'] ?> views</p>
    <div style="margin-top:var(--cv-space-3);"><?= KbArticleRenderer::render((string) $article['body'], $images, "/kb/{$id}/images") ?></div>
</div>

<div class="cv-card" style="max-width:44rem;margin:0 auto;">
    <p>Was this article helpful?</p>
    <form method="post" action="/kb/<?= $id ?>/rate" style="display:inline;"><?= csrf_field() ?>
        <input type="hidden" name="helpful" value="1">
        <button class="cv-btn cv-btn--secondary" type="submit">Yes (<?= (int) $article['helpful_count'] ?>)</button>
    </form>
    <form method="post" action="/kb/<?= $id ?>/rate" style="display:inline;"><?= csrf_field() ?>
        <input type="hidden" name="helpful" value="0">
        <button class="cv-btn cv-btn--secondary" type="submit">No (<?= (int) $article['unhelpful_count'] ?>)</button>
    </form>
</div>
