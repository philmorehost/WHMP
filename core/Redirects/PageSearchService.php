<?php

declare(strict_types=1);

namespace CodeVault\Redirects;

use CodeVault\Catalog\ProductRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Support\KnowledgebaseRepository;
use CodeVault\Domains\DomainRepository;

/**
 * AI-powered search service for 404 pages. When a client lands on a 404,
 * this service analyzes the URL and shows relevant search results for
 * products, pages, and knowledge base articles they might have intended to access.
 */
final class PageSearchService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductGroupRepository $groups,
        private readonly KnowledgebaseRepository $knowledgebase,
        private readonly DomainRepository $domains
    ) {
    }

    /**
     * Search for pages/products related to a 404 path.
     * Analyzes the URL and returns relevant results.
     * @return array{products: array, articles: array, suggestions: array}
     */
    public function searchByPath(string $notFoundPath): array
    {
        $notFoundPath = trim($notFoundPath, '/');

        // Extract keywords from the path
        $keywords = $this->extractKeywords($notFoundPath);

        $results = [
            'products' => [],
            'articles' => [],
            'suggestions' => [],
            'keywords' => $keywords,
        ];

        if (empty($keywords)) {
            // Default suggestions if no keywords found
            $results['suggestions'] = [
                ['title' => 'Browse Products', 'url' => '/store', 'icon' => '🛍️'],
                ['title' => 'View Your Services', 'url' => '/client/services', 'icon' => '⚙️'],
                ['title' => 'Knowledge Base', 'url' => '/knowledgebase', 'icon' => '📚'],
                ['title' => 'Support Tickets', 'url' => '/client/support/tickets', 'icon' => '🎫'],
            ];
            return $results;
        }

        // Search products by keywords
        $results['products'] = $this->searchProducts($keywords);

        // Search knowledge base articles
        $results['articles'] = $this->searchArticles($keywords);

        // Generate contextual suggestions based on the path
        $results['suggestions'] = $this->generateSuggestions($notFoundPath, $keywords);

        return $results;
    }

    /**
     * Extract meaningful keywords from a URL path
     * @return array<int, string>
     */
    private function extractKeywords(string $path): array
    {
        // Remove query strings
        $path = explode('?', $path)[0];

        // Split by common delimiters
        $parts = preg_split('/[\/-_]+/', $path);
        $parts = array_filter($parts, static fn ($p) => strlen($p) > 2 && !is_numeric($p));

        // Common WHMCS/WHMP path components to filter out
        $stopwords = ['clientarea', 'cart', 'store', 'client', 'action', 'id', 'page', 'php', 'index', 'viewproduct', 'productid'];

        $keywords = array_filter($parts, static fn ($p) => !in_array(strtolower($p), $stopwords, true));

        return array_values(array_slice($keywords, 0, 3)); // Limit to 3 keywords
    }

    /**
     * Search products by keywords
     * @param array<int, string> $keywords
     * @return array<int, array<string, mixed>>
     */
    private function searchProducts(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $searchTerm = '%' . implode('%', $keywords) . '%';

        $products = [];
        foreach ($keywords as $keyword) {
            $term = '%' . $keyword . '%';
            $found = $this->products->all([
                'search' => $term,
                'status' => 'active',
                'limit' => 3,
            ]) ?? [];

            foreach ($found as $product) {
                $key = (int) $product['id'];
                if (!isset($products[$key])) {
                    $products[$key] = [
                        'id' => $product['id'],
                        'name' => $product['name'],
                        'url' => '/store/' . $product['id'],
                        'type' => $product['type'] ?? 'general',
                    ];
                }
            }
        }

        return array_values(array_slice($products, 0, 4));
    }

    /**
     * Search knowledge base articles by keywords
     * @param array<int, string> $keywords
     * @return array<int, array<string, mixed>>
     */
    private function searchArticles(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $articles = [];
        foreach ($keywords as $keyword) {
            $term = '%' . $keyword . '%';
            $found = $this->knowledgebase->search($term, 3) ?? [];

            foreach ($found as $article) {
                $key = (int) $article['id'];
                if (!isset($articles[$key])) {
                    $articles[$key] = [
                        'id' => $article['id'],
                        'title' => $article['title'],
                        'category' => $article['category_name'] ?? 'General',
                        'url' => '/knowledgebase/article/' . $article['id'],
                    ];
                }
            }
        }

        return array_values(array_slice($articles, 0, 3));
    }

    /**
     * Generate contextual suggestions based on the path
     * @param array<int, string> $keywords
     * @return array<int, array<string, string>>
     */
    private function generateSuggestions(string $path, array $keywords): array
    {
        $suggestions = [];

        // Detect intent from path
        if (str_contains($path, 'domain')) {
            $suggestions[] = ['title' => 'Register a Domain', 'url' => '/store?group_id=domains', 'icon' => '🌐'];
            $suggestions[] = ['title' => 'Your Domains', 'url' => '/client/domains', 'icon' => '📋'];
        }

        if (str_contains($path, 'host') || str_contains($path, 'server') || str_contains($path, 'web')) {
            $suggestions[] = ['title' => 'Browse Hosting Plans', 'url' => '/store', 'icon' => '🖥️'];
            $suggestions[] = ['title' => 'Your Services', 'url' => '/client/services', 'icon' => '⚙️'];
        }

        if (str_contains($path, 'support') || str_contains($path, 'ticket') || str_contains($path, 'help')) {
            $suggestions[] = ['title' => 'Open Support Ticket', 'url' => '/client/support/tickets', 'icon' => '🎫'];
            $suggestions[] = ['title' => 'Knowledge Base', 'url' => '/knowledgebase', 'icon' => '📚'];
        }

        if (str_contains($path, 'invoice') || str_contains($path, 'bill') || str_contains($path, 'payment')) {
            $suggestions[] = ['title' => 'Your Invoices', 'url' => '/client/invoices', 'icon' => '📄'];
            $suggestions[] = ['title' => 'Account Settings', 'url' => '/client/account/profile', 'icon' => '⚙️'];
        }

        // Add default suggestions if we don't have specific ones
        if (count($suggestions) < 3) {
            $defaults = [
                ['title' => 'Browse All Products', 'url' => '/store', 'icon' => '🛍️'],
                ['title' => 'Knowledge Base', 'url' => '/knowledgebase', 'icon' => '📚'],
                ['title' => 'Contact Support', 'url' => '/client/support/tickets', 'icon' => '🎫'],
                ['title' => 'Your Dashboard', 'url' => '/client/dashboard', 'icon' => '🏠'],
            ];

            foreach ($defaults as $default) {
                if (!in_array($default['url'], array_column($suggestions, 'url'), true)) {
                    $suggestions[] = $default;
                    if (count($suggestions) >= 3) {
                        break;
                    }
                }
            }
        }

        return array_slice($suggestions, 0, 4);
    }
}
