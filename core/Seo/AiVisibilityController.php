<?php

declare(strict_types=1);

namespace CodeVault\Seo;

use CodeVault\Auth\AuthGuard;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Knowledgebase\KbArticleRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin-facing AI-visibility score (blueprint §5) — fetches each public
 * page live and checks it against the same signals AI answer-engines and
 * crawlers look for. Capped to the first 20 products/articles so a large
 * catalog doesn't turn one admin page load into dozens of HTTP round trips.
 */
final class AiVisibilityController
{
    private const CAP = 20;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AiVisibilityScorer $scorer,
        private readonly ProductRepository $products,
        private readonly KbArticleRepository $articles
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $paths = ['/', '/store', '/kb', '/downloads', '/status'];

        $allProducts = $this->products->all(includeHidden: false);
        $allArticles = $this->articles->all();

        foreach (array_slice($allProducts, 0, self::CAP) as $product) {
            $paths[] = "/store/{$product['id']}";
        }

        foreach (array_slice($allArticles, 0, self::CAP) as $article) {
            $paths[] = "/kb/{$article['id']}";
        }

        $results = $this->scorer->scoreAll($paths);
        $overall = $results === [] ? 0 : (int) round(array_sum(array_column($results, 'score')) / count($results));

        return $this->render('seo.ai-visibility', [
            'results' => $results,
            'overall' => $overall,
            'truncatedProducts' => max(0, count($allProducts) - self::CAP),
            'truncatedArticles' => max(0, count($allArticles) - self::CAP),
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — AI Visibility',
            'content' => $content,
        ]));
    }
}
