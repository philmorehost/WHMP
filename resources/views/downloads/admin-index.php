<?php
/** @var array<int, array<string, mixed>> $downloads */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Downloads</h1>
    <p><a href="/admin/downloads/create">Add Download</a> &middot; <a href="/admin/downloads/categories">Categories</a></p>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#downloads-table', 'placeholder' => 'Search downloads...']) ?>
    </div>
    <table class="cv-table" id="downloads-table">
        <thead><tr><th>Name</th><th>Category</th><th>Size</th><th>Downloads</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($downloads as $download): ?>
            <tr>
                <td><?= e($download['name']) ?></td>
                <td><?= e($download['category_name']) ?></td>
                <td><?= $download['file_size'] !== null ? number_format((int) $download['file_size'] / 1024, 1) . ' KB' : '-' ?></td>
                <td><?= (int) $download['download_count'] ?></td>
                <td>
                    <form method="post" action="/admin/downloads/<?= (int) $download['id'] ?>/delete"><?= csrf_field() ?>
                        <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($downloads === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No downloads yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
