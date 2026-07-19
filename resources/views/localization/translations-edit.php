<?php
/** @var array<string, mixed> $language */
/** @var array<int, array{key: string, default: string, current: string, overridden: bool}> $rows */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Translations — <?= e($language['name']) ?></h1>
    <p><a href="/admin/languages">&larr; Back to languages</a></p>
</div>

<div class="cv-card">
    <form method="post" action="/admin/languages/<?= (int) $language['id'] ?>/translations"><?= csrf_field() ?>
        <table class="cv-table">
            <thead><tr><th>Key</th><th>Default (English)</th><th>Value</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><code><?= e($row['key']) ?></code></td>
                    <td style="color:var(--cv-text-secondary);"><?= e($row['default']) ?></td>
                    <td>
                        <input class="cv-input" name="value[<?= e($row['key']) ?>]" value="<?= e($row['current']) ?>" style="width:100%;">
                        <?php if ($row['overridden']): ?>
                            <span class="cv-badge cv-badge--success" style="font-size:var(--cv-text-xs);">overridden</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-3);">Save Changes</button>
    </form>
</div>
