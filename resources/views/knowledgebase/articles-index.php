<?php
/** @var array<int, array<string, mixed>> $articles */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">KB Articles</h1>
    <p><a href="/admin/kb/articles/create">Add Article</a> &middot; <a href="/admin/kb/categories">Categories</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#kb-articles-table', 'placeholder' => 'Search articles...']) ?>
    </div>
    <table class="cv-table" id="kb-articles-table">
        <thead><tr><th>Title</th><th>Category</th><th>Views</th><th>Helpful</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($articles as $article): ?>
            <tr>
                <td><?= e($article['title']) ?></td>
                <td><?= e($article['category_name']) ?></td>
                <td><?= (int) $article['views'] ?></td>
                <td><?= (int) $article['helpful_count'] ?> / <?= (int) $article['unhelpful_count'] ?></td>
                <td>
                    <a class="cv-btn cv-btn--secondary" href="/admin/kb/articles/<?= (int) $article['id'] ?>/edit">Edit</a>
                    <form method="post" action="/admin/kb/articles/<?= (int) $article['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($articles === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No articles yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
