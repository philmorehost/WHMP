<?php
/** @var string $requestedPath */
/** @var array<int, array<string, mixed>> $products */
/** @var array<int, array<string, mixed>> $articles */
/** @var array<int, array<string, mixed>> $suggestions */
/** @var array<int, string> $keywords */
?>
<style>
/* ====== 404 Error Page Styles ====== */
.error-page {
    min-height: 100vh;
    background: linear-gradient(135deg, var(--cv-bg-surface) 0%, var(--cv-bg-surface-sunken) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}

.error-container {
    max-width: 900px;
    width: 100%;
}

.error-header {
    text-align: center;
    margin-bottom: 60px;
}

.error-code {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 8rem;
    font-weight: 900;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
    line-height: 1;
}

.error-title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 16px 0 12px 0;
}

.error-subtitle {
    color: var(--cv-text-secondary);
    font-size: 1.1rem;
    margin: 0 0 24px 0;
    line-height: 1.6;
}

.search-summary {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 16px 24px;
    margin-bottom: 40px;
    text-align: center;
    color: var(--cv-text-secondary);
    font-size: .95rem;
}

.search-summary strong {
    color: var(--cv-text-primary);
    font-weight: 600;
}

/* Results Grid */
.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 40px;
}

.results-section {
    margin-bottom: 48px;
}

.results-section__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.results-section__icon {
    font-size: 1.6rem;
}

/* Result Card */
.result-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.result-card:hover {
    transform: translateY(-4px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 8px 24px rgba(37,99,235,.12);
}

.result-card__badge {
    display: inline-block;
    background: linear-gradient(135deg, rgba(59,130,246,.1), rgba(37,99,235,.08));
    color: var(--cv-color-brand-500);
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    width: fit-content;
}

.result-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
}

.result-card__meta {
    color: var(--cv-text-secondary);
    font-size: .9rem;
}

.result-card__cta {
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--cv-color-brand-500);
    font-weight: 600;
    text-decoration: none;
    font-size: .95rem;
    transition: gap 0.2s;
}

.result-card:hover .result-card__cta {
    gap: 10px;
}

/* Suggestions */
.suggestions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}

.suggestion-link {
    background: linear-gradient(135deg, var(--cv-bg-surface), var(--cv-bg-surface-sunken));
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 20px;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 16px;
}

.suggestion-link:hover {
    transform: translateY(-3px);
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 8px 20px rgba(37,99,235,.15);
}

.suggestion-link__icon {
    font-size: 2rem;
    flex-shrink: 0;
}

.suggestion-link__content {
    flex: 1;
}

.suggestion-link__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0;
    font-size: 1rem;
}

.suggestion-link__arrow {
    color: var(--cv-color-brand-500);
    font-weight: 700;
    transition: transform 0.2s;
}

.suggestion-link:hover .suggestion-link__arrow {
    transform: translateX(4px);
}

/* Empty State */
.empty-results {
    text-align: center;
    padding: 40px;
    background: var(--cv-bg-surface);
    border-radius: 12px;
    border: 1px dashed var(--cv-border-default);
}

.empty-results__icon {
    font-size: 3rem;
    margin-bottom: 16px;
}

.empty-results__title {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0 0 12px 0;
}

.empty-results__text {
    color: var(--cv-text-secondary);
    margin: 0;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .error-code {
        font-size: 4rem;
    }
    .error-title {
        font-size: 1.5rem;
    }
    .results-grid {
        grid-template-columns: 1fr;
    }
    .suggestions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="error-page">
    <div class="error-container">
        <!-- Error Header -->
        <div class="error-header">
            <h1 class="error-code">404</h1>
            <h2 class="error-title">Page Not Found</h2>
            <p class="error-subtitle">
                We couldn't find what you're looking for, but we have some suggestions based on your search.
            </p>
        </div>

        <!-- Search Summary -->
        <?php if (!empty($keywords)): ?>
            <div class="search-summary">
                Searching for: <strong><?= e(implode(', ', $keywords)) ?></strong>
            </div>
        <?php endif; ?>

        <!-- Products Section -->
        <?php if (!empty($products)): ?>
            <div class="results-section">
                <h3 class="results-section__title">
                    <span class="results-section__icon">🛍️</span>
                    Popular Products
                </h3>
                <div class="results-grid">
                    <?php foreach ($products as $product): ?>
                        <a href="<?= e($product['url']) ?>" class="result-card">
                            <span class="result-card__badge">Product</span>
                            <h4 class="result-card__title"><?= e($product['name']) ?></h4>
                            <div class="result-card__cta">
                                View Details →
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Articles Section -->
        <?php if (!empty($articles)): ?>
            <div class="results-section">
                <h3 class="results-section__title">
                    <span class="results-section__icon">📚</span>
                    Knowledge Base Articles
                </h3>
                <div class="results-grid">
                    <?php foreach ($articles as $article): ?>
                        <a href="<?= e($article['url']) ?>" class="result-card">
                            <span class="result-card__badge"><?= e($article['category']) ?></span>
                            <h4 class="result-card__title"><?= e($article['title']) ?></h4>
                            <div class="result-card__cta">
                                Read Article →
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Suggestions -->
        <?php if (!empty($suggestions)): ?>
            <div class="results-section">
                <h3 class="results-section__title">
                    <span class="results-section__icon">✨</span>
                    Quick Links
                </h3>
                <div class="suggestions-grid">
                    <?php foreach ($suggestions as $suggestion): ?>
                        <a href="<?= e($suggestion['url']) ?>" class="suggestion-link">
                            <span class="suggestion-link__icon"><?= e($suggestion['icon']) ?></span>
                            <div class="suggestion-link__content">
                                <p class="suggestion-link__title"><?= e($suggestion['title']) ?></p>
                            </div>
                            <span class="suggestion-link__arrow">→</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Fallback if no results -->
        <?php if (empty($products) && empty($articles) && empty($suggestions)): ?>
            <div class="empty-results">
                <div class="empty-results__icon">🤔</div>
                <h3 class="empty-results__title">Hmm, we're not sure where that page went</h3>
                <p class="empty-results__text">Try browsing our store or contacting support for assistance.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
