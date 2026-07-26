<?php

declare(strict_types=1);

namespace CodeVault\Redirects;

use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Handles custom error pages (404, 405) with intelligent search results.
 * Analyzes the requested path and shows relevant products/articles.
 */
final class ErrorController
{
    public function __construct(
        private readonly View $view,
        private readonly PageSearchService $search,
        private readonly RedirectService $redirects
    ) {
    }

    public function notFound(Request $request): Response
    {
        $requestedPath = $request->path();

        // Check for WHMCS ?rp= query parameter (old URL format: index.php?rp=/store/cheap-dedicated-servers)
        $rpParam = $request->query('rp');
        if ($rpParam !== null) {
            $requestedPath = trim((string) $rpParam, '/');
            // Try redirecting the rp parameter value
            if ($this->redirects->shouldRedirect($requestedPath)) {
                $redirect = $this->redirects->getRedirectResponse($requestedPath);
                if ($redirect !== null) {
                    return Response::redirect($redirect['location'], 301);
                }
            }
        }

        // Check if this path should be redirected (from old WHMCS)
        if ($this->redirects->shouldRedirect($requestedPath)) {
            $redirect = $this->redirects->getRedirectResponse($requestedPath);
            if ($redirect !== null) {
                return Response::redirect($redirect['location'], $redirect['status']);
            }
        }

        // Search for relevant results
        $searchResults = $this->search->searchByPath($requestedPath);

        $content = $this->view->render('error.404', [
            'requestedPath' => $requestedPath,
            'products' => $searchResults['products'],
            'articles' => $searchResults['articles'],
            'suggestions' => $searchResults['suggestions'],
            'keywords' => $searchResults['keywords'],
        ]);

        return Response::html($content, 404);
    }

    public function methodNotAllowed(Request $request): Response
    {
        $content = $this->view->render('error.405', [
            'requestedPath' => $request->path(),
        ]);

        return Response::html($content, 405);
    }
}
