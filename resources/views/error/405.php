<?php
/** @var string $requestedPath */
?>
<style>
/* ====== 405 Error Page Styles ====== */
.error-page {
    min-height: 100vh;
    background: linear-gradient(135deg, var(--cv-bg-surface) 0%, var(--cv-bg-surface-sunken) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}

.error-container {
    max-width: 600px;
    width: 100%;
    text-align: center;
}

.error-header {
    margin-bottom: 40px;
}

.error-code {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 8rem;
    font-weight: 900;
    background: linear-gradient(135deg, #ef4444, #dc2626);
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
    font-size: 1rem;
    margin: 0 0 32px 0;
    line-height: 1.6;
}

.error-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
}

.error-button {
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.error-button--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.error-button--primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}

.error-button--secondary {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    color: var(--cv-text-primary);
}

.error-button--secondary:hover {
    border-color: var(--cv-color-brand-500);
    color: var(--cv-color-brand-500);
}

@media (max-width: 768px) {
    .error-code {
        font-size: 4rem;
    }
    .error-title {
        font-size: 1.5rem;
    }
    .error-actions {
        flex-direction: column;
    }
    .error-button {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="error-page">
    <div class="error-container">
        <div class="error-header">
            <h1 class="error-code">405</h1>
            <h2 class="error-title">Method Not Allowed</h2>
            <p class="error-subtitle">
                The request method is not supported for this resource. Please try a different approach or contact support if you believe this is an error.
            </p>
        </div>

        <div class="error-actions">
            <a href="/store" class="error-button error-button--primary">
                <span>🛍️</span>
                <span>Browse Store</span>
            </a>
            <a href="/client/dashboard" class="error-button error-button--secondary">
                <span>🏠</span>
                <span>Your Dashboard</span>
            </a>
        </div>
    </div>
</div>
