<?php
/** @var CodeVault\View $view */
/** @var string $title */
/** @var string $content */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php // No brand configured yet during install, so this one keeps a neutral name. ?>
    <title><?= e($title ?? 'WHMP Installer') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800&family=Inter:wght@400;600&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <style>
        :root {
            /* Brand Overrides matching PhilmoreHost */
            --cv-color-brand-500: #FF8F28;
            --cv-color-brand-600: #E07B1F;
            --cv-color-brand-50: rgba(255, 143, 40, 0.1);
            --cv-color-brand-100: rgba(255, 143, 40, 0.2);
            --cv-color-accent: #FF8F28;
            --cv-color-accent-hover: #E07B1F;

            /* Premium Dark Theme */
            --cv-bg-page: #020C1B;
            --cv-bg-surface: #0a192f;
            --cv-bg-surface-raised: #172a45;
            --cv-bg-surface-sunken: #020c1b;
            --cv-text-primary: #ffffff;
            --cv-text-secondary: #D9E2FF;
            --cv-text-tertiary: #8892b0;
            --cv-border-default: rgba(255, 255, 255, 0.1);
            --cv-border-subtle: rgba(255, 255, 255, 0.05);

            --cv-font-sans: 'Hanken Grotesk', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --cv-font-mono: 'JetBrains Mono', monospace;
        }

        body {
            background-color: var(--cv-bg-page);
            background-image: 
                radial-gradient(at 0% 0%, rgba(255, 143, 40, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(139, 92, 246, 0.03) 0px, transparent 50%);
            color: var(--cv-text-primary);
            font-family: var(--cv-font-sans);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: var(--cv-space-6) 0;
        }

        .installer-container {
            width: 100%;
            max-width: 42rem;
            padding: 0 var(--cv-space-4);
        }

        /* Glassmorphism card style */
        .cv-card {
            background: var(--cv-bg-surface);
            border: 1px solid var(--cv-border-default);
            border-radius: var(--cv-radius-lg);
            padding: var(--cv-space-6);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            margin-bottom: var(--cv-space-4);
            transition: transform var(--cv-transition-base), box-shadow var(--cv-transition-base);
        }

        .cv-card:hover {
            box-shadow: 0 24px 48px rgba(255, 143, 40, 0.05);
        }

        .cv-card__title {
            font-family: 'Hanken Grotesk', sans-serif;
            font-weight: 800;
            font-size: var(--cv-text-xl);
            color: #ffffff;
            margin: 0 0 var(--cv-space-2) 0;
            letter-spacing: -0.02em;
        }

        .cv-label {
            font-weight: 600;
            color: var(--cv-text-secondary);
            margin-bottom: var(--cv-space-2);
            display: block;
        }

        .cv-input {
            background: var(--cv-bg-surface-sunken);
            border: 1px solid var(--cv-border-default);
            color: #ffffff;
            border-radius: var(--cv-radius-md);
            padding: 0.75rem 1rem;
            width: 100%;
            box-sizing: border-box;
            transition: border-color var(--cv-transition-base), box-shadow var(--cv-transition-base);
        }

        .cv-input:focus {
            border-color: var(--cv-color-brand-500);
            box-shadow: 0 0 0 3px rgba(255, 143, 40, 0.2);
            outline: none;
        }

        .cv-btn {
            background: var(--cv-color-brand-500);
            color: #020C1B;
            font-family: 'Hanken Grotesk', sans-serif;
            font-weight: 700;
            border-radius: var(--cv-radius-md);
            padding: 0.75rem 1.5rem;
            border: none;
            cursor: pointer;
            transition: background var(--cv-transition-base), transform var(--cv-transition-base);
            width: 100%;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            box-sizing: border-box;
        }

        .cv-btn:hover:not(:disabled) {
            background: var(--cv-color-brand-600);
            transform: translateY(-1px);
        }

        .cv-btn:active:not(:disabled) {
            transform: translateY(1px);
        }

        .cv-btn:disabled {
            background: var(--cv-color-neutral-800);
            color: var(--cv-text-tertiary);
            cursor: not-allowed;
        }

        .cv-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cv-table th {
            text-align: left;
            padding: var(--cv-space-3);
            color: var(--cv-text-secondary);
            border-bottom: 2px solid var(--cv-border-default);
            font-weight: 600;
        }

        .cv-table td {
            padding: var(--cv-space-3);
            border-bottom: 1px solid var(--cv-border-subtle);
            color: var(--cv-text-primary);
        }

        .cv-badge {
            font-size: var(--cv-text-xs);
            padding: var(--cv-space-1) var(--cv-space-2);
            border-radius: var(--cv-radius-sm);
            font-weight: 600;
        }

        .cv-badge--success {
            background: rgba(31, 157, 85, 0.15);
            color: #4ade80;
            border: 1px solid rgba(31, 157, 85, 0.3);
        }

        .cv-badge--danger {
            background: rgba(220, 38, 38, 0.15);
            color: #f87171;
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: #f87171;
            padding: var(--cv-space-3) var(--cv-space-4);
            border-radius: var(--cv-radius-md);
            margin-bottom: var(--cv-space-4);
        }

        .alert-error ul {
            margin: 0;
            padding-left: var(--cv-space-4);
        }

        /* Progress indicator */
        .progress-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: var(--cv-space-6);
            position: relative;
        }

        .progress-bar::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--cv-border-default);
            z-index: 1;
        }

        .progress-step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--cv-bg-surface-sunken);
            border: 2px solid var(--cv-border-default);
            color: var(--cv-text-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-family: 'Hanken Grotesk', sans-serif;
            z-index: 2;
            transition: all var(--cv-transition-base);
        }

        .progress-step.active {
            border-color: var(--cv-color-brand-500);
            background: var(--cv-bg-surface);
            color: var(--cv-color-brand-500);
            box-shadow: 0 0 10px rgba(255, 143, 40, 0.2);
        }

        .progress-step.complete {
            border-color: var(--cv-color-brand-500);
            background: var(--cv-color-brand-500);
            color: #020C1B;
        }

        .logo-header {
            text-align: center;
            margin-bottom: var(--cv-space-6);
        }

        .logo-header h1 {
            font-family: 'Hanken Grotesk', sans-serif;
            font-weight: 800;
            font-size: var(--cv-text-2xl);
            letter-spacing: -0.03em;
            margin: 0;
            color: #ffffff;
        }

        .logo-header span {
            color: var(--cv-color-brand-500);
        }
    </style>
</head>
<body>
<div class="installer-container">
    <div class="logo-header">
        <h1>Philmore<span>WHMP</span></h1>
        <p style="color: var(--cv-text-tertiary); margin: 5px 0 0 0; font-size: var(--cv-text-sm);">Next-Gen Hosting & Provisioning Platform Installer</p>
    </div>

    <!-- Progress indicator -->
    <?php
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $step = 1;
    if ($currentPath === '/install/database') $step = 2;
    if ($currentPath === '/install/admin') $step = 3;
    if ($currentPath === '/install/finish') $step = 4;
    ?>
    <div class="progress-bar">
        <div class="progress-step <?= $step >= 1 ? ($step == 1 ? 'active' : 'complete') : '' ?>">1</div>
        <div class="progress-step <?= $step >= 2 ? ($step == 2 ? 'active' : 'complete') : '' ?>">2</div>
        <div class="progress-step <?= $step >= 3 ? ($step == 3 ? 'active' : 'complete') : '' ?>">3</div>
        <div class="progress-step <?= $step >= 4 ? ($step == 4 ? 'active' : 'complete') : '' ?>">4</div>
    </div>

    <main>
        <?= $content ?>
    </main>
</div>
</body>
</html>
