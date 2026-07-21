<?php
/** @var array<int, array<string, mixed>> $items attachment rows */
/** @var string $baseUrl e.g. "/admin/tickets/5/attachments" */
if (empty($items)) {
    return;
}
?>
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;">
    <?php foreach ($items as $att):
        $url = $baseUrl . '/' . (int) $att['id'];
        $mime = (string) $att['mime_type'];
        $isImage = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
        $isPdf = $mime === 'application/pdf';
        $kb = number_format(((int) $att['size_bytes']) / 1024, 0);
    ?>
        <?php if ($isImage): ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e($att['original_name']) ?>">
                <img src="<?= e($url) ?>" alt="<?= e($att['original_name']) ?>" loading="lazy" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--cv-border-default);">
            </a>
        <?php else: ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="cv-btn cv-btn--secondary" style="display:inline-flex;align-items:center;gap:.4rem;">
                <?= $isPdf ? '📄' : '📎' ?> <?= e($att['original_name']) ?>
                <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">(<?= e($kb) ?> KB<?= $isPdf ? ' · click to preview' : '' ?>)</span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
