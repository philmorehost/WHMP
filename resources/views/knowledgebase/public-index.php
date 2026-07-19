<?php
/** @var array<int, array<string, mixed>> $categories */
/** @var string $query */
/** @var array<int, array<string, mixed>>|null $results */
/** @var string|null $aiAnswer */
?>
<div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Knowledgebase</h1>
    <form method="get" action="/kb">
        <div class="cv-field" style="display:flex;gap:var(--cv-space-2);margin-bottom:0;">
            <input class="cv-input" type="text" name="q" value="<?= e($query) ?>" placeholder="Search articles..." aria-label="Search knowledgebase articles">
            <button class="cv-btn" type="submit">Search</button>
        </div>
    </form>
</div>

<?php if ($results !== null): ?>
    <?php if ($aiAnswer !== null): ?>
        <div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">AI Answer</h2>
            <div style="white-space:pre-wrap;"><?= e($aiAnswer) ?></div>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-2);">Generated from the articles below — verify against the source article for anything important.</p>
        </div>
    <?php endif; ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;">
        <h2 class="cv-card__title">Search Results for "<?= e($query) ?>"</h2>
        <ul>
            <?php foreach ($results as $article): ?>
                <li><a href="/kb/<?= (int) $article['id'] ?>"><?= e($article['title']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($results === []): ?>
            <p style="color:var(--cv-text-secondary);">No articles matched your search.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($categories as $category): ?>
        <div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title"><?= e($category['name']) ?></h2>
            <ul>
                <?php foreach ($category['articles'] as $article): ?>
                    <li><a href="/kb/<?= (int) $article['id'] ?>"><?= e($article['title']) ?></a></li>
                <?php endforeach; ?>
                <?php if ($category['articles'] === []): ?>
                    <li style="color:var(--cv-text-secondary);list-style:none;">No articles yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    <?php if ($categories === []): ?>
        <div class="cv-card" style="max-width:44rem;margin:0 auto;">
            <p style="color:var(--cv-text-secondary);">No knowledgebase articles yet.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
