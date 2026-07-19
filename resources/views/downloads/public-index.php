<?php
/** @var array<int, array<string, mixed>> $categories */
?>
<div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Downloads</h1>
</div>

<?php foreach ($categories as $category): ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title"><?= e($category['name']) ?></h2>
        <table class="cv-table">
            <thead><tr><th>Name</th><th>Size</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($category['downloads'] as $download): ?>
                <tr>
                    <td>
                        <?= e($download['name']) ?>
                        <?php if (!empty($download['description'])): ?>
                            <div style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e($download['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $download['file_size'] !== null ? number_format((int) $download['file_size'] / 1024, 1) . ' KB' : '-' ?></td>
                    <td><a class="cv-btn cv-btn--secondary" href="/downloads/<?= (int) $download['id'] ?>">Download</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($category['downloads'] === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No files yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
<?php if ($categories === []): ?>
    <div class="cv-card" style="max-width:44rem;margin:0 auto;">
        <p style="color:var(--cv-text-secondary);">No downloads available yet.</p>
    </div>
<?php endif; ?>
