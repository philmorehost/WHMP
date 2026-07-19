<?php
/** @var string $slug */
/** @var array{name: string, description: string, version: string, author: string} $metadata */
/** @var string $output */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($metadata['name']) ?></h1>
    <p style="color:var(--cv-text-secondary);"><?= e($metadata['description']) ?></p>
    <p><a href="/admin/addons">&larr; Back to addons</a></p>
</div>

<?= $output ?>
