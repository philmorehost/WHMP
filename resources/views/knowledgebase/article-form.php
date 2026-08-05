<?php
/** @var array<string, mixed>|null $article */
/** @var array<int, array<string, mixed>> $categories */
/** @var array<int, array<string, mixed>> $images */
/** @var string|null $error */
/** @var bool $imgUploaded */
/** @var bool $imgDeleted */
/** @var string|null $imgError */

use CodeVault\Knowledgebase\KbArticleRenderer;

$isEdit = $article !== null;
$images ??= [];
$imgUploaded ??= false;
$imgDeleted ??= false;
$imgError ??= null;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= $isEdit ? 'Edit Article' : 'Add Article' ?></h1>
    <p><a href="/admin/kb/articles">&larr; Back to articles</a></p>
    <?php if ($error !== null): ?>
        <div class="cv-field-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($imgUploaded): ?>
        <div class="cv-alert cv-alert--success">Image saved.</div>
    <?php endif; ?>
    <?php if ($imgDeleted): ?>
        <div class="cv-alert cv-alert--success">Image removed.</div>
    <?php endif; ?>
    <?php if ($imgError !== null): ?>
        <div class="cv-alert cv-alert--danger"><?= e($imgError) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);background:var(--cv-bg-surface-sunken);border:1px dashed var(--cv-border-default);" data-kb-copilot>
    <h3 style="margin-top:0;">✨ Write with AI</h3>
    <div class="cv-field">
        <label class="cv-label">Brief — what should this article cover?</label>
        <textarea class="cv-input" rows="2" data-kb-copilot-brief placeholder="e.g. how a client resets their cPanel password from the client area"></textarea>
    </div>
    <div class="cv-field">
        <label class="cv-label">Reference material (optional) — the real steps, page names, and button labels for this topic</label>
        <textarea class="cv-input" rows="4" data-kb-copilot-reference placeholder="e.g. 1) Client logs in and opens My Services. 2) Clicks the service. 3) Clicks the &quot;Log In to Control Panel&quot; button (this signs them into cPanel directly, no separate cPanel password needed)."></textarea>
        <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">
            The AI has never seen this app's real screens — without this, it can only write in general terms and
            will flag the exact click-path as unconfirmed. Paste the real steps here and every button label, page
            name, and step in the draft will come from what you typed, not a guess.
        </span>
    </div>
    <div style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
        <button type="button" class="cv-btn cv-btn--secondary" data-kb-copilot-action="write">Write Draft</button>
        <button type="button" class="cv-btn cv-btn--secondary" data-kb-copilot-action="refine">Refine Current Text</button>
        <span data-kb-copilot-status style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);"></span>
    </div>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <form method="post" action="<?= $isEdit ? '/admin/kb/articles/' . (int) $article['id'] . '/edit' : '/admin/kb/articles' ?>" id="kb-article-form">
        <?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Category</label>
            <select class="cv-input" name="category_id" required>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $isEdit && (int) $article['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Title</label>
            <input class="cv-input" name="title" data-kb-title value="<?= e((string) ($article['title'] ?? '')) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Body</label>
            <textarea class="cv-input" name="body" rows="12" data-kb-body required><?= e((string) ($article['body'] ?? '')) ?></textarea>
            <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">
                Plain text — separate paragraphs with a blank line. To place an image, paste its <code>[[image:ID]]</code>
                token (shown below each image) where you want it to appear; unplaced images are added at the end.
            </span>
        </div>
        <button class="cv-btn" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Article' ?></button>
    </form>
</div>

<?php if ($isEdit): ?>
    <?php $id = (int) $article['id']; ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h3 style="margin-top:0;">🖼️ Images</h3>

        <?php if ($images !== []): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:var(--cv-space-3);margin-bottom:var(--cv-space-4);">
                <?php foreach ($images as $image): ?>
                    <div style="border:1px solid var(--cv-border-default);border-radius:8px;padding:var(--cv-space-2);">
                        <img src="/admin/kb/articles/<?= $id ?>/images/<?= (int) $image['id'] ?>" alt="" style="width:100%;height:110px;object-fit:contain;background:#fff;border-radius:4px;">
                        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin:6px 0;">
                            <?= $image['source'] === 'ai_generated' ? '✨ AI-generated' : '📤 Uploaded' ?>
                        </div>
                        <code style="display:block;font-size:var(--cv-text-xs);background:var(--cv-bg-surface-sunken);padding:4px 6px;border-radius:4px;margin-bottom:6px;">[[image:<?= (int) $image['id'] ?>]]</code>
                        <form method="post" action="/admin/kb/articles/<?= $id ?>/images/<?= (int) $image['id'] ?>/delete" data-confirm="Remove this image?" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" style="width:100%;padding:4px;font-size:var(--cv-text-xs);" type="submit">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No images yet.</p>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-4);">
            <form method="post" action="/admin/kb/articles/<?= $id ?>/images" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label class="cv-label">Upload images</label>
                <input class="cv-input" type="file" name="images[]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" multiple style="margin-bottom:var(--cv-space-2);">
                <button class="cv-btn cv-btn--secondary" type="submit">Upload</button>
            </form>
            <form method="post" action="/admin/kb/articles/<?= $id ?>/images/generate">
                <?= csrf_field() ?>
                <label class="cv-label">✨ Generate a diagram with AI</label>
                <input class="cv-input" type="text" name="prompt" placeholder="e.g. a flowchart of the 3 steps to reset a password" style="margin-bottom:var(--cv-space-2);">
                <button class="cv-btn cv-btn--secondary" type="submit">Generate Diagram</button>
            </form>
        </div>
    </div>

    <div class="cv-card">
        <h3 style="margin-top:0;">Preview</h3>
        <div style="border:1px solid var(--cv-border-default);border-radius:8px;padding:var(--cv-space-3);">
            <?= KbArticleRenderer::render((string) $article['body'], $images, "/admin/kb/articles/{$id}/images") ?>
        </div>
    </div>
<?php endif; ?>
