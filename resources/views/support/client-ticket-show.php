<?php
/** @var array<string, mixed> $ticket */
/** @var array<int, array<string, mixed>> $replies */
/** @var array<int, array<int, array<string, mixed>>> $attachments grouped by reply_id (0 = opening message) */
$id = (int) $ticket['id'];
?>
<style>
/* ====== Ticket Detail Page Styles ====== */
.ticket-detail-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.ticket-detail-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(59,130,246,.12) 0%, transparent 70%);
    pointer-events: none;
}
.ticket-detail-hero__content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    flex-wrap: wrap;
}
.ticket-detail-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    margin-bottom: 16px;
    transition: all 0.2s;
}
.ticket-detail-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.ticket-detail-hero__left {
    flex: 1;
    min-width: 300px;
}
.ticket-detail-hero__ticket-id {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .9rem;
    font-weight: 700;
    color: rgba(255,255,255,.75);
    margin: 0 0 8px 0;
}
.ticket-detail-hero__subject {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0;
    line-height: 1.2;
}
.ticket-detail-hero__meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 20px;
    font-size: .9rem;
}
.ticket-detail-hero__meta-item {
    color: rgba(255,255,255,.75);
}
.ticket-detail-hero__meta-label {
    color: rgba(255,255,255,.6);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}

/* Thread Container */
.ticket-thread {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-bottom: 40px;
    max-width: 900px;
    margin-left: auto;
    margin-right: auto;
}

/* Message Styles */
.message {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: all 0.2s;
}
.message:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.message--staff {
    border-left: 4px solid #3b82f6;
    background: linear-gradient(135deg, rgba(59,130,246,0.03), transparent);
}
.message__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--cv-border-default);
}
.message__author-section {
    flex: 1;
}
.message__author {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.message__timestamp {
    font-size: .85rem;
    color: var(--cv-text-secondary);
    margin: 0;
}
.message__badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.message__content {
    color: var(--cv-text-primary);
    font-size: .95rem;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
    margin-bottom: 12px;
}

/* Attachments */
.message__attachments {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--cv-border-default);
}

/* Rating Widget */
.message__rating {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--cv-border-default);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.rating-label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    font-weight: 600;
}
.rating-stars {
    display: flex;
    gap: 8px;
}
.rating-star-btn {
    background: transparent;
    border: 2px solid var(--cv-color-brand-300);
    color: var(--cv-color-brand-500);
    border-radius: 6px;
    padding: 8px 12px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.rating-star-btn:hover {
    background: var(--cv-color-brand-100);
    border-color: var(--cv-color-brand-500);
    transform: scale(1.1);
}
.rating-star-btn:active {
    transform: scale(0.95);
}
.rating-display {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--cv-text-primary);
    font-weight: 700;
}

/* Reply Form Section */
.reply-section {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    padding: 32px;
    max-width: 900px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 40px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.reply-section__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 24px 0;
}
.reply-section__textarea {
    width: 100%;
    min-height: 120px;
    padding: 16px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: .9rem;
    color: var(--cv-text-primary);
    background: var(--cv-bg-surface-sunken);
    resize: vertical;
    margin-bottom: 20px;
}
.reply-section__textarea:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.reply-section__upload-area {
    border: 2px dashed var(--cv-border-default);
    border-radius: 8px;
    padding: 24px;
    text-align: center;
    background: var(--cv-bg-surface-sunken);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 20px;
}
.reply-section__upload-area:hover {
    border-color: var(--cv-color-brand-500);
    background: rgba(37,99,235,0.05);
}
.reply-section__upload-icon {
    font-size: 2rem;
    margin-bottom: 8px;
}
.reply-section__upload-text {
    color: var(--cv-text-secondary);
    font-size: .9rem;
    margin: 0;
}
.reply-section__upload-input {
    display: none;
}
.reply-section__file-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.reply-section__file-item {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    padding: 12px;
    text-align: center;
    font-size: .8rem;
}
.reply-section__submit {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 32px;
    font-weight: 700;
    font-size: .95rem;
    cursor: pointer;
    transition: all 0.2s;
}
.reply-section__submit:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(16,185,129,.3);
}

/* Satisfaction Rating Section */
.satisfaction-section {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    padding: 32px;
    text-align: center;
    max-width: 900px;
    margin-left: auto;
    margin-right: auto;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.satisfaction-section__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 24px 0;
}
.satisfaction-section__stars {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
}
.satisfaction-star {
    background: transparent;
    border: 2px solid var(--cv-border-default);
    color: var(--cv-text-secondary);
    border-radius: 8px;
    padding: 12px 18px;
    font-weight: 700;
    font-size: 1.2rem;
    cursor: pointer;
    transition: all 0.2s;
}
.satisfaction-star:hover {
    border-color: var(--cv-color-brand-500);
    color: var(--cv-color-brand-500);
    background: rgba(37,99,235,0.05);
    transform: scale(1.1);
}
.satisfaction-star:active {
    transform: scale(0.95);
}
.satisfaction-thanks {
    background: rgba(16,185,129,0.1);
    border: 1px solid rgba(16,185,129,0.3);
    color: #059669;
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .ticket-detail-hero {
        padding: 32px 24px;
    }
    .ticket-detail-hero__content {
        flex-direction: column;
    }
    .ticket-detail-hero__subject {
        font-size: 1.5rem;
    }
    .ticket-detail-hero__meta {
        grid-template-columns: 1fr;
    }
    .message {
        padding: 16px;
    }
    .reply-section {
        padding: 20px;
    }
    .message__header {
        flex-direction: column;
    }
}
</style>

<div>
    <!-- Hero Section -->
    <div class="ticket-detail-hero">
        <div class="ticket-detail-hero__content">
            <div class="ticket-detail-hero__left">
                <a href="/client/tickets" class="ticket-detail-hero__back">
                    <span>←</span>
                    <span>Back to Tickets</span>
                </a>
                <p class="ticket-detail-hero__ticket-id">Ticket #<?= $id ?></p>
                <h1 class="ticket-detail-hero__subject"><?= e($ticket['subject']) ?></h1>
            </div>
            <div class="ticket-detail-hero__meta">
                <div>
                    <p class="ticket-detail-hero__meta-label">Department</p>
                    <p class="ticket-detail-hero__meta-item"><?= e($ticket['department_name']) ?></p>
                </div>
                <div>
                    <p class="ticket-detail-hero__meta-label">Status</p>
                    <p class="ticket-detail-hero__meta-item">
                        <?php if ($ticket['status'] === 'closed'): ?>
                            ✓ Resolved
                        <?php elseif ($ticket['status'] === 'answered'): ?>
                            🟠 Your Reply Needed
                        <?php elseif ($ticket['status'] === 'customer-reply'): ?>
                            🔵 Support Reviewing
                        <?php else: ?>
                            🟢 Open
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Ticket Thread -->
    <div class="ticket-thread">
        <?php foreach ($replies as $i => $reply):
            $replyAttachments = $attachments[(int) $reply['id']] ?? [];
            if ($i === 0) { $replyAttachments = array_merge($attachments[0] ?? [], $replyAttachments); }
            $isStaff = !empty($reply['staff_id']);
        ?>
            <div class="message <?= $isStaff ? 'message--staff' : '' ?>">
                <div class="message__header">
                    <div class="message__author-section">
                        <div class="message__author">
                            <?= e($reply['author_name']) ?>
                            <?php if ($isStaff): ?>
                                <span class="message__badge">🔧 Support Team</span>
                            <?php else: ?>
                                <span class="message__badge" style="background: linear-gradient(135deg, #10b981, #059669);">👤 You</span>
                            <?php endif; ?>
                        </div>
                        <p class="message__timestamp"><?= e($reply['created_at']) ?></p>
                    </div>
                </div>

                <p class="message__content"><?= e($reply['message']) ?></p>

                <?php if (!empty($replyAttachments)): ?>
                    <div class="message__attachments">
                        <?= $view->partial('partials.ticket-attachments', ['items' => $replyAttachments, 'baseUrl' => "/client/tickets/{$id}/attachments"]) ?>
                    </div>
                <?php endif; ?>

                <!-- Rating Widget for Staff Replies -->
                <?php
                $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
                if ($isStaff && $settingsRepo->get('support.ticket_rating_enabled', '1') === '1'):
                ?>
                    <div class="message__rating">
                        <span class="rating-label">Was this helpful?</span>
                        <?php if (!empty($reply['rating'])): ?>
                            <div class="rating-display">
                                <?php for ($s = 0; $s < (int) $reply['rating']; $s++): ?>
                                    ⭐
                                <?php endfor; ?>
                                <span><?= (int) $reply['rating'] ?> / 5</span>
                            </div>
                        <?php else: ?>
                            <div class="rating-stars">
                                <form method="post" action="/client/tickets/<?= $id ?>/rate" style="display: contents;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reply_id" value="<?= (int) $reply['id'] ?>">
                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                        <button class="rating-star-btn" type="submit" name="rating" value="<?= $star ?>">
                                            <?php for ($s = 0; $s < $star; $s++): ?>⭐<?php endfor; ?>
                                        </button>
                                    <?php endfor; ?>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Reply Form or Satisfaction Section -->
    <?php if ($ticket['status'] !== 'closed'): ?>
        <!-- Reply Form -->
        <div class="reply-section">
            <h2 class="reply-section__title">Send a Reply</h2>
            <form method="post" action="/client/tickets/<?= $id ?>/reply" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <textarea class="reply-section__textarea" name="message" placeholder="Type your reply here... Tell us how we can help!" required></textarea>

                <!-- File Upload Area -->
                <div class="reply-section__upload-area" onclick="document.getElementById('file-input').click()">
                    <div class="reply-section__upload-icon">📎</div>
                    <p class="reply-section__upload-text">Click to upload files or drag & drop</p>
                    <p style="color: var(--cv-text-secondary); font-size: .8rem; margin: 8px 0 0 0;">Images, PDFs, and documents up to 10 MB each</p>
                    <input class="reply-section__upload-input" id="file-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.zip" onchange="updateFileList()">
                </div>
                <div class="reply-section__file-list" id="file-list"></div>

                <button class="reply-section__submit" type="submit">Send Reply →</button>
            </form>

            <script>
                function updateFileList() {
                    const fileInput = document.getElementById('file-input');
                    const fileList = document.getElementById('file-list');
                    fileList.innerHTML = '';

                    Array.from(fileInput.files).forEach(file => {
                        const div = document.createElement('div');
                        div.className = 'reply-section__file-item';
                        div.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)';
                        fileList.appendChild(div);
                    });
                }

                // Drag & drop
                const uploadArea = document.querySelector('.reply-section__upload-area');
                uploadArea.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadArea.style.borderColor = 'var(--cv-color-brand-500)';
                    uploadArea.style.background = 'rgba(37,99,235,0.1)';
                });
                uploadArea.addEventListener('dragleave', () => {
                    uploadArea.style.borderColor = 'var(--cv-border-default)';
                    uploadArea.style.background = 'var(--cv-bg-surface-sunken)';
                });
                uploadArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    document.getElementById('file-input').files = e.dataTransfer.files;
                    updateFileList();
                    uploadArea.style.borderColor = 'var(--cv-border-default)';
                    uploadArea.style.background = 'var(--cv-bg-surface-sunken)';
                });
            </script>
        </div>
    <?php elseif ($ticket['satisfaction_rating'] === null): ?>
        <!-- Satisfaction Rating Form -->
        <div class="satisfaction-section">
            <h2 class="satisfaction-section__title">How was our support?</h2>
            <p style="color: var(--cv-text-secondary); margin: 0 0 24px 0;">Your feedback helps us improve our service</p>
            <form method="post" action="/client/tickets/<?= $id ?>/rate" style="display: contents;">
                <?= csrf_field() ?>
                <div class="satisfaction-section__stars">
                    <?php for ($star = 1; $star <= 5; $star++): ?>
                        <button class="satisfaction-star" type="submit" name="rating" value="<?= $star ?>">
                            <?php for ($s = 0; $s < $star; $s++): ?>⭐<?php endfor; ?>
                        </button>
                    <?php endfor; ?>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Thank You Message -->
        <div class="satisfaction-thanks">
            <p style="margin: 0; font-weight: 700;">✓ You rated this ticket <?= (int) $ticket['satisfaction_rating'] ?> / 5 stars</p>
            <p style="margin: 8px 0 0 0; font-size: .9rem;">Thank you for your feedback! It helps us serve you better.</p>
        </div>
    <?php endif; ?>
</div>
